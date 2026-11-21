<?php

namespace Tests\Feature\Admin;

use App\Enums\PhotoKind;
use App\Enums\UserRole;
use App\Models\Building;
use App\Models\PlacePhoto;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PanoramaFixture;
use Tests\Support\PhotoFixture;
use Tests\TestCase;

/**
 * Photos of buildings and rooms: upload (normal photos and 360° photos), the limit per place, cover and order, delete,
 * what the map serves, and the admin-only access.
 */
class PlacePhotoTest extends TestCase
{
    use RefreshDatabase;

    private Building $library;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CampusMapSeeder::class);
        $this->library = Building::firstWhere('code', 'b8');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function uploadTo(Building|Room $place, PhotoKind $kind, array $files)
    {
        $route = $place instanceof Building ? 'admin.buildings.photos.store' : 'admin.rooms.photos.store';

        return $this->actingAs($this->admin())->post(route($route, $place), ['kind' => $kind->value, 'photos' => $files]);
    }

    public function test_a_normal_photo_is_saved_as_a_web_copy_and_a_thumbnail_without_exif(): void
    {
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('library.jpg', PanoramaFixture::jpeg(3000, 2000, [PanoramaFixture::LATITUDE, PanoramaFixture::LONGITUDE]))])
            ->assertRedirect(route('admin.buildings.edit', $this->library))
            ->assertSessionHas('status', 'Photos added.');

        $photo = $this->library->photos()->sole();
        $this->assertSame(PhotoKind::Photo, $photo->kind);
        $this->assertSame([1600, 1067], [$photo->width, $photo->height]);

        Storage::disk('public')->assertExists([$photo->image_path, $photo->thumb_path]);
        $copy = Storage::disk('public')->path($photo->image_path);
        $this->assertSame(['image/jpeg'], [mime_content_type($copy)]);
        $exif = @exif_read_data($copy);
        $this->assertFalse(is_array($exif) && isset($exif['GPSLatitude']), 'The web copy must carry no GPS position.');
    }

    public function test_png_and_webp_photos_are_accepted(): void
    {
        $this->uploadTo($this->library, PhotoKind::Photo, [
            PhotoFixture::upload('front.png', PhotoFixture::png()),
            PhotoFixture::upload('side.webp', PhotoFixture::webp()),
        ])->assertSessionHas('status', 'Photos added.');

        $this->assertSame(2, $this->library->photos()->count());
    }

    public function test_a_360_photo_gets_the_walkway_panorama_copies(): void
    {
        $this->uploadTo($this->library, PhotoKind::Panorama, [PanoramaFixture::upload('lobby.jpg', 4096, 2048, null)])
            ->assertSessionHas('status', 'Photos added.');

        $photo = $this->library->photos()->sole();
        $this->assertSame([PhotoKind::Panorama, 4096, 2048], [$photo->kind, $photo->width, $photo->height]);
        Storage::disk('public')->assertExists([$photo->image_path, $photo->thumb_path]);
    }

    public function test_a_sideways_phone_photo_is_turned_upright(): void
    {
        // Stored 300 wide and 200 tall, tagged "rotate 90° clockwise to show it": it should be saved 200 wide, 300 tall.
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('phone.jpg', PhotoFixture::jpeg(300, 200, 6))])
            ->assertSessionHas('status', 'Photos added.');

        $photo = $this->library->photos()->sole();
        $this->assertSame([200, 300], [$photo->width, $photo->height]);
    }

    public function test_the_first_photo_is_the_cover_and_new_photos_go_last(): void
    {
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('first.jpg', PhotoFixture::jpeg())]);
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('second.jpg', PhotoFixture::jpeg())]);

        $positions = $this->library->photos()->pluck('position')->all();
        $this->assertSame([0, 1], $positions);
    }

    public function test_a_place_keeps_at_most_six_photos(): void
    {
        PlacePhoto::factory()->count(5)->for($this->library, 'place')->create();

        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('one.jpg', PhotoFixture::jpeg()), PhotoFixture::upload('two.jpg', PhotoFixture::jpeg())])
            ->assertRedirect();

        // The rejected upload's message is shown on the edit page (the session errors are read there, not by the test).
        $this->actingAs($this->admin())->get(route('admin.buildings.edit', $this->library))
            ->assertSee('A place can have up to 6 photos. Delete one before adding more.', false);

        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('six.jpg', PhotoFixture::jpeg())])
            ->assertSessionHasNoErrors();
        $this->assertSame(6, $this->library->photos()->count());
    }

    public function test_a_360_photo_must_be_twice_as_wide_as_it_is_tall(): void
    {
        $this->uploadTo($this->library, PhotoKind::Panorama, [PanoramaFixture::upload('square.jpg', 2048, 2048, null)])
            ->assertSessionHasErrors(['photos.0']);

        $this->assertSame(0, $this->library->photos()->count());
    }

    public function test_a_file_that_is_not_a_picture_is_not_saved(): void
    {
        // The upload's type is judged by its name in tests, so it passes the type check and fails to decode.
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('notes.jpg', 'just some text')])
            ->assertSessionHas('warning', '1 photo(s) could not be read and were not added.');

        $this->assertSame(0, $this->library->photos()->count());
    }

    public function test_the_kind_is_required(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.buildings.photos.store', $this->library), ['photos' => [PhotoFixture::upload('a.jpg', PhotoFixture::jpeg())]])
            ->assertSessionHasErrors(['kind']);
    }

    public function test_moving_a_photo_swaps_it_with_its_neighbour(): void
    {
        $photos = PlacePhoto::factory()->count(3)->for($this->library, 'place')->create();
        $ids = $photos->pluck('id')->all();
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.buildings.photos.move', [$this->library, $photos[0]]), ['direction' => 'down'])
            ->assertRedirect(route('admin.buildings.edit', $this->library));
        $this->assertSame([$ids[1], $ids[0], $ids[2]], $this->library->photos()->pluck('id')->all());

        $this->actingAs($admin)->patch(route('admin.buildings.photos.move', [$this->library, $photos[2]]), ['direction' => 'up']);
        $this->assertSame([$ids[1], $ids[2], $ids[0]], $this->library->photos()->pluck('id')->all());

        // The cover cannot move further up, and the last photo cannot move further down.
        $this->actingAs($admin)->patch(route('admin.buildings.photos.move', [$this->library, $photos[1]]), ['direction' => 'up']);
        $this->actingAs($admin)->patch(route('admin.buildings.photos.move', [$this->library, $photos[0]]), ['direction' => 'down']);
        $this->assertSame([$ids[1], $ids[2], $ids[0]], $this->library->photos()->pluck('id')->all());
    }

    public function test_deleting_a_photo_removes_its_files(): void
    {
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('gone.jpg', PhotoFixture::jpeg())]);
        $photo = $this->library->photos()->sole();

        $this->actingAs($this->admin())
            ->delete(route('admin.buildings.photos.destroy', [$this->library, $photo]))
            ->assertRedirect(route('admin.buildings.edit', $this->library))
            ->assertSessionHas('status', 'Photo deleted.');

        $this->assertDatabaseMissing('place_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing([$photo->image_path, $photo->thumb_path]);
    }

    public function test_a_photo_can_only_be_changed_through_its_own_place(): void
    {
        $room = Room::firstOrFail();
        $photo = PlacePhoto::factory()->for($room, 'place')->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.buildings.photos.destroy', [$this->library, $photo]))
            ->assertNotFound();

        $this->assertDatabaseHas('place_photos', ['id' => $photo->id]);
    }

    public function test_the_map_serves_each_places_photos_in_order(): void
    {
        $this->uploadTo($this->library, PhotoKind::Panorama, [PanoramaFixture::upload('lobby.jpg', 4096, 2048, null)]);
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('front.jpg', PhotoFixture::jpeg(300, 200))]);

        $features = collect($this->getJson(route('api.map'))->assertOk()->json('data.buildings.features'))
            ->keyBy('properties.id');
        $photos = $features['b8']['properties']['photos'];

        $this->assertSame(['panorama', 'photo'], array_column($photos, 'kind'));
        $this->assertSame([4096, 2048], [$photos[0]['width'], $photos[0]['height']]);
        $this->assertStringStartsWith('/storage/place-photos/', $photos[1]['image']);
        $this->assertStringStartsWith('/storage/place-photos/', $photos[1]['thumb']);
        $this->assertSame([], $features['b0']['properties']['photos']);
    }

    public function test_a_room_photo_is_served_with_its_room(): void
    {
        $room = Room::firstOrFail();
        $this->uploadTo($room, PhotoKind::Photo, [PhotoFixture::upload('lab.jpg', PhotoFixture::jpeg())]);

        $entrances = collect($this->getJson(route('api.map'))->json('data.entrances.features'))->keyBy('properties.id');

        $this->assertCount(1, $entrances[$room->code]['properties']['photos']);
    }

    public function test_only_admins_can_upload_move_or_delete_photos(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $photo = PlacePhoto::factory()->for($this->library, 'place')->create();

        $this->actingAs($student)
            ->post(route('admin.buildings.photos.store', $this->library), ['kind' => 'photo', 'photos' => [PhotoFixture::upload('a.jpg', PhotoFixture::jpeg())]])
            ->assertForbidden();
        $this->actingAs($student)
            ->patch(route('admin.buildings.photos.move', [$this->library, $photo]), ['direction' => 'up'])
            ->assertForbidden();
        $this->actingAs($student)
            ->delete(route('admin.buildings.photos.destroy', [$this->library, $photo]))
            ->assertForbidden();

        $this->assertDatabaseHas('place_photos', ['id' => $photo->id]);
    }

    public function test_the_photo_editor_shows_a_hover_preview_for_each_photo(): void
    {
        $this->uploadTo($this->library, PhotoKind::Photo, [PhotoFixture::upload('front.jpg', PhotoFixture::jpeg())]);
        $this->uploadTo($this->library, PhotoKind::Panorama, [PanoramaFixture::upload('lobby.jpg', 4096, 2048, null)]);
        $normal = $this->library->photos()->where('kind', PhotoKind::Photo)->sole();
        $panorama = $this->library->photos()->where('kind', PhotoKind::Panorama)->sole();

        $this->actingAs($this->admin())->get(route('admin.buildings.edit', $this->library))
            ->assertOk()
            ->assertSee('data-still="'.$normal->imageUrl().'"', false)
            ->assertSee('data-still="'.$panorama->thumbUrl().'"', false);
    }
}
