<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Requests\Admin\StorePanoramaRequest;
use App\Http\Requests\Admin\UpdatePanoramaRequest;
use App\Models\Building;
use App\Models\Panorama;
use App\Models\Path;
use App\Models\User;
use App\Services\CampusMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PanoramaFixture;
use Tests\TestCase;

/**
 * Admin "360° photos": upload a photo, fix its position and north, show it to users or hide it, delete it. Only
 * photos with north set can be shown, and only shown photos reach GET /api/map.
 */
class PanoramaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Panorama::DISK);
        Building::factory()->campus()->create();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upload(array $data): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post(route('admin.panoramas.store'), ['idempotency_key' => (string) Str::uuid(), ...$data]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function save(Panorama $panorama, array $changes = []): TestResponse
    {
        return $this->actingAs($this->admin())->put(route('admin.panoramas.update', $panorama), [
            'name' => $panorama->name,
            'floor' => $panorama->floor,
            'latitude' => $panorama->latitude,
            'longitude' => $panorama->longitude,
            'north_offset' => $panorama->north_offset,
            'is_visible' => $panorama->is_visible ? '1' : '0',
            ...$changes,
        ]);
    }

    /**
     * @return list<string> codes of the photos GET /api/map sends
     */
    private function apiCodes(): array
    {
        return $this->getJson(route('api.map'))->assertOk()->json('data.panoramas.features.*.properties.id');
    }

    /**
     * The list page's row for one photo.
     */
    private function row(string $html, string $code): string
    {
        $this->assertSame(1, preg_match('/data-panorama-row="'.$code.'".*?<\/tr>/s', $html, $match), "row {$code}");

        return $match[0];
    }

    public function test_only_admins_can_open_or_change_the_photos(): void
    {
        $photo = Panorama::factory()->withNorth()->create(['name' => 'Gate']);
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->get(route('admin.panoramas.index'))->assertRedirect(route('login'));
        $this->actingAs($student)->get(route('admin.panoramas.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.panoramas.edit', $photo))->assertForbidden();
        $this->actingAs($student)->post(route('admin.panoramas.store'), ['idempotency_key' => (string) Str::uuid(), 'photo' => PanoramaFixture::upload()])->assertForbidden();
        $this->actingAs($student)->put(route('admin.panoramas.update', $photo), ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($student)->patch(route('admin.panoramas.visibility', $photo), ['is_visible' => '1'])->assertForbidden();
        $this->actingAs($student)->delete(route('admin.panoramas.destroy', $photo))->assertForbidden();

        $this->assertSame(['Gate', false], [$photo->fresh()->name, $photo->fresh()->is_visible]);
        $this->assertSame(1, Panorama::count());
        $this->actingAs($this->admin())->get(route('admin.panoramas.index'))->assertOk()->assertSee('360° photos');
    }

    public function test_the_list_shows_every_photo_in_code_order_with_its_badges(): void
    {
        // A walkway along latitude 8.5412; 0.0002° of latitude is about 22 m.
        Path::factory()->create(['line' => [[124.7480, 8.5412], [124.7486, 8.5412]]]);
        Panorama::factory()->visible()->create(['code' => 'pn10', 'name' => 'On the walkway', 'latitude' => 8.54121, 'longitude' => 124.7483]);
        Panorama::factory()->create(['code' => 'pn2', 'name' => 'Under the trees', 'latitude' => 8.5414, 'longitude' => 124.7483]);
        Panorama::factory()->withNorth()->create(['code' => 'pn3', 'name' => 'Upstairs', 'floor' => 2]);

        $html = $this->actingAs($this->admin())->get(route('admin.panoramas.index'))
            ->assertOk()
            ->assertSeeInOrder(['Under the trees', 'Upstairs', 'On the walkway'])
            ->getContent();

        $onWalkway = $this->row($html, 'pn10');
        $this->assertStringContainsString('Shown', $onWalkway);
        $this->assertStringNotContainsString('North not set', $onWalkway);
        $this->assertStringNotContainsString('Check position', $onWalkway);
        $this->assertMatchesRegularExpression('/>\s*Hide</', $onWalkway);

        $underTrees = $this->row($html, 'pn2');
        $this->assertStringContainsString('Hidden', $underTrees);
        $this->assertStringContainsString('North not set', $underTrees);
        $this->assertStringContainsString('Check position', $underTrees);
        $this->assertStringContainsString('22 m from a walkway', $underTrees);
        $this->assertMatchesRegularExpression('/<button type="submit" disabled/', $underTrees);

        $upstairs = $this->row($html, 'pn3');
        $this->assertStringContainsString('Check position', $upstairs);
        $this->assertStringContainsString('no walkway on this floor', $upstairs);
        $this->assertDoesNotMatchRegularExpression('/<button type="submit" disabled/', $upstairs);
    }

    public function test_an_uploaded_photo_is_added_hidden_at_its_gps_position(): void
    {
        $this->upload(['photo' => PanoramaFixture::upload(gps: [8.541122, 124.7483552]), 'name' => ' Main walkway by the gate '])
            ->assertRedirect(route('admin.panoramas.map', ['photo' => 'pn1']))
            ->assertSessionHas('status', 'Main walkway by the gate was uploaded. Check its position, then set north.')
            ->assertSessionMissing('warning');

        $photo = Panorama::query()->sole();
        $this->assertSame(['pn1', 'Main walkway by the gate', 1, null, false], [$photo->code, $photo->name, $photo->floor, $photo->north_offset, $photo->is_visible]);
        $this->assertEqualsWithDelta(8.541122, $photo->latitude, 0.000001);
        $this->assertEqualsWithDelta(124.7483552, $photo->longitude, 0.000001);
        Storage::disk(Panorama::DISK)->assertExists([$photo->image_path, $photo->thumb_path]);
        $this->assertSame([], $this->apiCodes());
    }

    public function test_an_upload_gets_the_next_code_and_a_default_name(): void
    {
        Panorama::factory()->create(['code' => 'pn10']);

        $this->upload(['photo' => PanoramaFixture::upload()])->assertRedirect(route('admin.panoramas.map', ['photo' => 'pn11']));

        $this->assertSame('Photo point 11', Panorama::query()->where('code', 'pn11')->value('name'));
    }

    public function test_a_photo_without_usable_gps_is_put_in_the_middle_of_the_campus(): void
    {
        $center = app(CampusMap::class)->campusCenter();

        $this->upload(['photo' => PanoramaFixture::upload(gps: null)])
            ->assertSessionHas('warning', 'This photo has no GPS position, so it was put in the middle of the campus. Drag the pin to where it was taken.');
        $this->upload(['photo' => PanoramaFixture::upload(gps: [14.5995, 120.9842])])
            ->assertSessionHas('warning', 'The GPS position in this photo is not on campus, so it was put in the middle of the campus. Drag the pin to where it was taken.');

        foreach (Panorama::all() as $photo) {
            $this->assertSame([$center['latitude'], $center['longitude']], [$photo->latitude, $photo->longitude]);
        }
    }

    public function test_an_upload_must_be_a_full_360_jpeg(): void
    {
        $this->upload([])->assertSessionHasErrors(['photo' => 'Choose a 360° photo to upload.']);
        $this->upload(['photo' => PanoramaFixture::upload(width: 2048, height: 1536)])
            ->assertSessionHasErrors(['photo' => 'Choose a full 360° photo: twice as wide as it is tall, at least 2048 pixels wide.']);
        $this->upload(['photo' => PanoramaFixture::upload(width: 1024, height: 512)])
            ->assertSessionHasErrors(['photo' => 'Choose a full 360° photo: twice as wide as it is tall, at least 2048 pixels wide.']);
        $this->upload(['photo' => UploadedFile::fake()->image('walkway.png', 2048, 1024)])
            ->assertSessionHasErrors(['photo' => 'Choose a JPEG photo (.jpg).']);

        $this->assertSame(0, Panorama::count());
        $this->assertSame([], Storage::disk(Panorama::DISK)->allFiles());
    }

    public function test_a_photo_too_big_for_the_server_gets_a_clear_message(): void
    {
        $message = StorePanoramaRequest::tooBigMessage();
        $this->assertSame('This photo is too big. The server accepts photos up to 40 MB.', $message);

        // Over the 40 MB limit.
        $this->upload(['photo' => UploadedFile::fake()->create('walkway.jpg', 41 * 1024, 'image/jpeg')])
            ->assertSessionHasErrors(['photo' => $message]);

        // Refused by PHP itself (over upload_max_filesize).
        $refused = new UploadedFile(PanoramaFixture::upload()->getPathname(), 'walkway.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);
        $this->upload(['photo' => $refused])->assertSessionHasErrors(['photo' => $message]);

        // The whole request over post_max_size: back to the list, which says so.
        $this->actingAs($this->admin())
            ->call('POST', route('admin.panoramas.store'), [], [], [], ['CONTENT_LENGTH' => (string) (UploadedFile::getMaxFilesize() + 1)])
            ->assertRedirect(route('admin.panoramas.index', ['too_large' => 1]));
        $this->actingAs($this->admin())->get(route('admin.panoramas.index', ['too_large' => 1]))
            ->assertOk()
            ->assertSee($message)
            ->assertSee('data-max-bytes="'.(40 * 1024 * 1024).'"', false);

        $this->assertSame(0, Panorama::count());
    }

    public function test_an_admin_moves_a_photo_sets_north_and_shows_it(): void
    {
        $photo = Panorama::factory()->create();

        $this->actingAs($this->admin())->get(route('admin.panoramas.map', ['photo' => $photo->code]))
            ->assertOk()
            ->assertSee('data-selected="'.$photo->code.'"', false);

        $this->save($photo, ['name' => 'Main walkway 5', 'latitude' => '8.5410', 'longitude' => '124.7478', 'north_offset' => '287.456', 'is_visible' => '1'])
            ->assertRedirect(route('admin.panoramas.index'))
            ->assertSessionHas('status', 'Main walkway 5 was saved.');

        $photo->refresh();
        $this->assertSame(['Main walkway 5', 8.541, 124.7478, 287.46, true], [$photo->name, $photo->latitude, $photo->longitude, $photo->north_offset, $photo->is_visible]);
        $this->assertSame([$photo->code], $this->apiCodes());
    }

    public function test_north_of_360_is_saved_as_0_and_a_blank_north_as_not_set(): void
    {
        $photo = Panorama::factory()->withNorth(45)->create();

        $this->save($photo, ['north_offset' => '360'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $photo->fresh()->north_offset);

        $this->save($photo, ['north_offset' => ''])->assertSessionHasNoErrors();
        $this->assertNull($photo->fresh()->north_offset);
    }

    public function test_a_photo_cannot_be_shown_without_north_or_moved_off_campus(): void
    {
        $photo = Panorama::factory()->create();

        $this->save($photo, ['north_offset' => '', 'is_visible' => '1'])
            ->assertSessionHasErrors(['is_visible' => 'Set north before showing this photo to users.']);
        $this->save($photo, ['latitude' => '14.5995', 'longitude' => '120.9842'])
            ->assertSessionHasErrors(['latitude' => 'Place the pin on campus.']);
        $this->save($photo, ['north_offset' => '400'])->assertSessionHasErrors('north_offset');
        $this->save($photo, ['floor' => '0'])->assertSessionHasErrors('floor');

        $this->assertFalse($photo->fresh()->is_visible);
        $this->assertSame(8.5412066, $photo->fresh()->latitude);
    }

    public function test_clearing_north_hides_a_shown_photo(): void
    {
        $photo = Panorama::factory()->visible()->create();

        $photo->update(['north_offset' => null]);

        $this->assertFalse($photo->fresh()->is_visible);
        $this->assertSame([], $this->apiCodes());
    }

    public function test_the_list_toggle_shows_and_hides_a_photo(): void
    {
        $photo = Panorama::factory()->withNorth()->create(['name' => 'Gate']);
        $this->assertSame([], $this->apiCodes());

        $this->actingAs($this->admin())->patch(route('admin.panoramas.visibility', $photo), ['is_visible' => '1'])
            ->assertRedirect(route('admin.panoramas.index'))
            ->assertSessionHas('status', 'Gate is now shown to users.');
        $this->assertSame([$photo->code], $this->apiCodes());

        $this->actingAs($this->admin())->patch(route('admin.panoramas.visibility', $photo), ['is_visible' => '0'])
            ->assertSessionHas('status', 'Gate is now hidden from users.');
        $this->assertSame([], $this->apiCodes());
    }

    public function test_the_list_toggle_refuses_a_photo_without_north(): void
    {
        $photo = Panorama::factory()->create(['name' => 'Gate']);

        $this->actingAs($this->admin())->patch(route('admin.panoramas.visibility', $photo), ['is_visible' => '1'])
            ->assertSessionHasErrors(['is_visible' => 'Gate: Set north before showing this photo to users.']);

        $this->assertFalse($photo->fresh()->is_visible);
    }

    public function test_deleting_a_photo_removes_its_images(): void
    {
        $this->upload(['photo' => PanoramaFixture::upload()]);
        $photo = Panorama::query()->sole();
        $this->assertCount(2, Storage::disk(Panorama::DISK)->allFiles());

        $this->actingAs($this->admin())->delete(route('admin.panoramas.destroy', $photo))
            ->assertRedirect(route('admin.panoramas.index'))
            ->assertSessionHas('status', "{$photo->name} was deleted.");

        $this->assertSame(0, Panorama::count());
        $this->assertSame([], Storage::disk(Panorama::DISK)->allFiles());
    }

    public function test_the_edit_map_gets_every_photo_point_hidden_ones_too(): void
    {
        $shown = Panorama::factory()->visible()->create(['code' => 'pn1', 'name' => 'Gate']);
        Panorama::factory()->create(['code' => 'pn2', 'name' => 'Walkway']);

        $html = $this->actingAs($this->admin())->get(route('admin.panoramas.map', ['photo' => $shown->code]))->assertOk()->getContent();

        $this->assertSame(1, preg_match("/data-points='([^']*)'/", $html, $match) + preg_match('/data-points="([^"]*)"/', $html, $double));
        $raw = $match[1] ?? $double[1];
        $points = json_decode(html_entity_decode($raw), true);
        $this->assertSame(['pn1', 'pn2'], array_column($points, 'code'));
        $this->assertSame(false, $points[1]['is_visible']);
        $this->assertSame(124.7482424, $points[1]['longitude']);
    }

    public function test_the_route_debug_list_gives_admins_every_photo_with_why_it_may_be_skipped(): void
    {
        Panorama::factory()->visible(45)->create(['code' => 'pn2', 'name' => 'Walkway']);
        Panorama::factory()->withNorth(10)->create(['code' => 'pn1', 'name' => 'Gate']);
        Panorama::factory()->create(['code' => 'pn10', 'name' => 'Corner', 'floor' => 2]);

        $photos = $this->actingAs($this->admin())->getJson(route('admin.panoramas.route-debug'))->assertOk()->json('data');

        $this->assertSame([
            ['id' => 'pn1', 'name' => 'Gate', 'floor' => 1, 'north_offset' => 10, 'is_visible' => false, 'coord' => [124.7482424, 8.5412066]],
            ['id' => 'pn2', 'name' => 'Walkway', 'floor' => 1, 'north_offset' => 45, 'is_visible' => true, 'coord' => [124.7482424, 8.5412066]],
            ['id' => 'pn10', 'name' => 'Corner', 'floor' => 2, 'north_offset' => null, 'is_visible' => false, 'coord' => [124.7482424, 8.5412066]],
        ], $photos);
    }

    public function test_only_admins_get_the_route_debug_list(): void
    {
        Panorama::factory()->create();
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->getJson(route('admin.panoramas.route-debug'))->assertUnauthorized();
        $this->actingAs($student)->getJson(route('admin.panoramas.route-debug'))->assertForbidden();
    }

    public function test_the_navigate_debug_panel_links_the_route_debug_list_for_admins_only(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $link = 'data-dbg-panoramas="'.route('admin.panoramas.route-debug').'"';

        $this->actingAs($this->admin())->get(route('navigate', ['debug' => 1]))->assertOk()->assertSee($link, false);
        $this->actingAs($this->admin())->get(route('navigate'))->assertOk()->assertDontSee('data-dbg-panoramas', false);
        $this->actingAs($student)->get(route('navigate', ['debug' => 1]))->assertOk()
            ->assertSee('data-dbg="panoramas"', false)->assertDontSee('data-dbg-panoramas=', false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function listing(array $query = []): string
    {
        return $this->actingAs($this->admin())->get(route('admin.panoramas.index', $query))->assertOk()->getContent();
    }

    /** The codes of the rows on a list page, in order. */
    private function rowCodes(string $html): array
    {
        preg_match_all('/data-panorama-row="(pn\d+)"/', $html, $matches);

        return $matches[1];
    }

    public function test_the_list_shows_five_photos_a_page_with_pagination(): void
    {
        foreach (range(1, 6) as $number) {
            Panorama::factory()->create(['code' => "pn{$number}", 'name' => "Photo {$number}"]);
        }

        $first = $this->listing();
        $this->assertSame(['pn1', 'pn2', 'pn3', 'pn4', 'pn5'], $this->rowCodes($first));
        $this->assertStringContainsString('6 photos', $first);

        $second = $this->listing(['page' => 2]);
        $this->assertSame(['pn6'], $this->rowCodes($second));
    }

    public function test_the_list_searches_by_name_or_code(): void
    {
        Panorama::factory()->create(['code' => 'pn1', 'name' => 'Main gate']);
        Panorama::factory()->create(['code' => 'pn2', 'name' => 'Library']);
        Panorama::factory()->create(['code' => 'pn3', 'name' => 'Gate house']);

        $this->assertSame(['pn1', 'pn3'], $this->rowCodes($this->listing(['q' => 'GATE'])));
        $this->assertSame(['pn2'], $this->rowCodes($this->listing(['q' => 'pn2'])));
        $this->assertStringContainsString('No photos match this search or filter.', $this->listing(['q' => 'nowhere']));
    }

    public function test_the_list_filters_by_status(): void
    {
        Path::factory()->create(['line' => [[124.7480, 8.5412], [124.7486, 8.5412]]]);
        // On the walkway, shown.
        Panorama::factory()->visible()->create(['code' => 'pn1', 'latitude' => 8.54121, 'longitude' => 124.7483]);
        // Under the trees: hidden, no north, 22 m from the walkway.
        Panorama::factory()->create(['code' => 'pn2', 'latitude' => 8.5414, 'longitude' => 124.7483]);
        // Upstairs, no walkway there: hidden, north set, check position.
        Panorama::factory()->withNorth()->create(['code' => 'pn3', 'floor' => 2]);

        $this->assertSame(['pn1'], $this->rowCodes($this->listing(['status' => 'shown'])));
        $this->assertSame(['pn2', 'pn3'], $this->rowCodes($this->listing(['status' => 'hidden'])));
        $this->assertSame(['pn2'], $this->rowCodes($this->listing(['status' => 'no_north'])));
        $this->assertSame(['pn2', 'pn3'], $this->rowCodes($this->listing(['status' => 'check'])));
        $this->assertSame(['pn1', 'pn2', 'pn3'], $this->rowCodes($this->listing(['status' => 'all'])));
        $this->assertSame(['pn1', 'pn2', 'pn3'], $this->rowCodes($this->listing(['status' => 'unknown'])));
    }

    public function test_the_list_keeps_the_search_and_filter_across_pages(): void
    {
        foreach (range(1, 7) as $number) {
            Panorama::factory()->create(['code' => "pn{$number}", 'name' => $number <= 6 ? "Gate {$number}" : 'Library']);
        }

        $html = $this->listing(['q' => 'gate']);
        $this->assertStringContainsString('q=gate', $html);
        $this->assertSame(['pn1', 'pn2', 'pn3', 'pn4', 'pn5'], $this->rowCodes($html));
        $this->assertSame(['pn6'], $this->rowCodes($this->listing(['q' => 'gate', 'page' => 2])));
    }

    /**
     * @param  list<array<string, mixed>>  $photos
     */
    private function saveMany(array $photos): TestResponse
    {
        return $this->actingAs($this->admin())->putJson(route('admin.panoramas.batch'), ['photos' => $photos]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function photoDetails(Panorama $panorama, array $changes = []): array
    {
        return [
            'code' => $panorama->code,
            'name' => $panorama->name,
            'floor' => $panorama->floor,
            'latitude' => $panorama->latitude,
            'longitude' => $panorama->longitude,
            'north_offset' => $panorama->north_offset,
            'is_visible' => $panorama->is_visible,
            ...$changes,
        ];
    }

    public function test_several_photos_are_saved_in_one_request(): void
    {
        $gate = Panorama::factory()->withNorth(30)->create(['code' => 'pn1', 'name' => 'Gate']);
        $walkway = Panorama::factory()->create(['code' => 'pn2', 'name' => 'Walkway']);

        $this->saveMany([
            $this->photoDetails($gate, ['name' => 'Main gate', 'is_visible' => true]),
            $this->photoDetails($walkway, ['name' => 'Walkway by the gym', 'latitude' => 8.5415, 'north_offset' => '287.456']),
        ])->assertOk()->assertJson(['saved' => ['pn1', 'pn2']]);

        $this->assertSame(['Main gate', true], [$gate->fresh()->name, $gate->fresh()->is_visible]);
        $this->assertSame(['Walkway by the gym', 8.5415, 287.46], [$walkway->fresh()->name, $walkway->fresh()->latitude, $walkway->fresh()->north_offset]);
    }

    public function test_if_one_photo_fails_nothing_is_saved_and_the_failing_photo_is_named(): void
    {
        $gate = Panorama::factory()->withNorth(30)->create(['code' => 'pn1', 'name' => 'Gate']);
        $walkway = Panorama::factory()->create(['code' => 'pn2', 'name' => 'Walkway']);

        $this->saveMany([
            $this->photoDetails($gate, ['name' => 'Main gate']),
            // Far off campus.
            $this->photoDetails($walkway, ['name' => 'Walkway', 'latitude' => 0.5]),
        ])->assertStatus(422)->assertJsonValidationErrors(['photos.1.latitude']);

        $this->assertSame('Gate', $gate->fresh()->name);
        $this->assertSame(0, Panorama::query()->where('name', 'Main gate')->count());
    }

    public function test_several_photos_cannot_be_shown_without_north(): void
    {
        $gate = Panorama::factory()->withNorth(30)->create(['code' => 'pn1']);
        $noNorth = Panorama::factory()->create(['code' => 'pn2']);

        $this->saveMany([
            $this->photoDetails($gate, ['is_visible' => true]),
            $this->photoDetails($noNorth, ['is_visible' => true]),
        ])->assertStatus(422)->assertJsonValidationErrors(['photos.1.is_visible' => UpdatePanoramaRequest::NORTH_REQUIRED]);

        $this->assertFalse($gate->fresh()->is_visible);
        $this->assertFalse($noNorth->fresh()->is_visible);
    }

    public function test_saving_several_photos_is_for_admins_only_and_needs_a_known_photo(): void
    {
        $photo = Panorama::factory()->create(['code' => 'pn1']);
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->putJson(route('admin.panoramas.batch'), ['photos' => [$this->photoDetails($photo)]])->assertUnauthorized();
        $this->actingAs($student)->putJson(route('admin.panoramas.batch'), ['photos' => [$this->photoDetails($photo)]])->assertForbidden();
        $this->saveMany([$this->photoDetails($photo, ['code' => 'pn99'])])->assertStatus(422)->assertJsonValidationErrors(['photos.0.code']);
        $this->saveMany([])->assertStatus(422)->assertJsonValidationErrors(['photos']);
    }

    public function test_the_old_edit_address_opens_the_map_with_that_photo(): void
    {
        $photo = Panorama::factory()->create(['code' => 'pn4']);

        $this->actingAs($this->admin())->get(route('admin.panoramas.edit', $photo))
            ->assertRedirect(route('admin.panoramas.map', ['photo' => 'pn4']))
            ->assertStatus(301);
    }

    public function test_the_map_page_is_admin_only_and_shows_hidden_photos_too(): void
    {
        Panorama::factory()->create(['code' => 'pn1']);
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->get(route('admin.panoramas.map'))->assertRedirect(route('login'));
        $this->actingAs($student)->get(route('admin.panoramas.map'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.panoramas.map'))->assertOk()->assertSee('data-panorama-map-editor', false);
    }

    public function test_the_list_is_headed_all_photos_with_the_count(): void
    {
        Panorama::factory()->create(['code' => 'pn1']);
        Panorama::factory()->create(['code' => 'pn2']);

        $html = $this->actingAs($this->admin())->get(route('admin.panoramas.index'))
            ->assertOk()
            ->assertSee('All photos')
            ->assertSee('(2 in total)')
            ->getContent();

        $this->assertStringNotContainsString('>Photo points <', $html);
    }

    public function test_the_upload_form_is_in_a_dialog_opened_from_the_page_button(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.panoramas.index'))
            ->assertOk()
            ->assertSee('data-open-upload', false)
            ->assertSee('Upload photo')
            ->assertSee('data-upload-modal', false)
            ->assertSee('data-panorama-upload', false)
            ->assertSee('data-upload-submit', false)
            ->assertSee('data-max-bytes="'.(40 * 1024 * 1024).'"', false)
            ->getContent();

        // Closed on a normal visit: no errors to show, so no reopening.
        $this->assertMatchesRegularExpression('/<div id="upload-modal" data-upload-modal[^>]*\shidden/', $html);
        $this->assertStringNotContainsString('data-reopen', $html);
    }

    public function test_a_rejected_upload_opens_the_dialog_again_with_its_errors(): void
    {
        $this->actingAs($this->admin())->from(route('admin.panoramas.index'))
            ->post(route('admin.panoramas.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertRedirect(route('admin.panoramas.index'));

        $html = $this->get(route('admin.panoramas.index'))
            ->assertOk()
            ->assertSee('data-reopen', false)
            ->assertSee('Choose a JPEG photo (.jpg).')
            ->getContent();

        $this->assertMatchesRegularExpression('/<div id="upload-modal" data-upload-modal[^>]*data-reopen/', $html);
        $this->assertDoesNotMatchRegularExpression('/<div id="upload-modal" data-upload-modal[^>]*\shidden/', $html);
    }
}
