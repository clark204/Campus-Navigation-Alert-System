<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Map data is edited in another app: an admin downloads the whole map as campus.geojson and imports it back. Names
 * are edited on this site, so an import keeps the names of codes already on the map.
 */
class CampusMapFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CampusMapSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function exported(): string
    {
        return $this->actingAs($this->admin())->get(route('admin.map.export'))
            ->assertOk()
            ->assertDownload('campus.geojson')
            ->getContent();
    }

    private function upload(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('campus.geojson', $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(array &$map, string $id): array
    {
        foreach ($map['features'] as $index => $feature) {
            if ($feature['properties']['id'] === $id) {
                return [$index, $feature];
            }
        }

        $this->fail("No feature {$id} in the export.");
    }

    public function test_only_admins_can_see_and_use_the_map_data(): void
    {
        $this->get(route('admin.map.index'))->assertRedirect(route('login'));

        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->actingAs($student)->get(route('admin.map.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.map.export'))->assertForbidden();
        $this->actingAs($student)
            ->post(route('admin.map.import'), ['file' => $this->upload('{}')])
            ->assertForbidden();
    }

    public function test_the_export_is_one_geojson_feature_collection_with_every_part_of_the_map(): void
    {
        $this->actingAs($this->admin())->get(route('admin.map.index'))
            ->assertOk()
            ->assertSee(route('admin.map.export'));

        $map = json_decode($this->exported(), true);

        $this->assertSame('FeatureCollection', $map['type']);
        $kinds = collect($map['features'])->countBy('properties.kind')->all();
        $this->assertSame(['building' => 17, 'path' => 16, 'room' => 29, 'stair' => 2], $kinds);

        [, $library] = $this->feature($map, 'b8');
        $this->assertSame('Polygon', $library['geometry']['type']);
        $this->assertSame('the Library', $library['properties']['landmark_name']);
    }

    public function test_the_export_leaves_photos_out(): void
    {
        $map = json_decode($this->exported(), true);

        foreach ($map['features'] as $feature) {
            $this->assertArrayNotHasKey('photos', $feature['properties']);
        }
    }

    public function test_an_exported_file_imports_back_without_changes(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => $this->upload($this->exported())])
            ->assertRedirect(route('admin.map.index'))
            ->assertSessionHas('status', 'Added 0, updated 0.');

        $this->assertSame(17, Building::count());
    }

    public function test_an_import_keeps_the_names_on_the_map_and_takes_the_other_details_from_the_file(): void
    {
        $map = json_decode($this->exported(), true);
        [$index, $library] = $this->feature($map, 'b8');
        $library['properties']['name'] = 'Main Library';
        $library['properties']['landmark_name'] = 'the Main Library';
        $library['properties']['floors'] = 4;
        $map['features'][$index] = $library;

        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => $this->upload(json_encode($map))])
            ->assertSessionHas('status', 'Added 0, updated 1.');

        $library = Building::firstWhere('code', 'b8');
        $this->assertSame(['Library Building', 'the Library'], [$library->name, $library->landmark_name]);
        $this->assertSame(4, $library->floors);
        $this->assertSame(17, Building::count());
    }

    public function test_a_new_code_takes_its_name_from_the_file(): void
    {
        $map = json_decode($this->exported(), true);
        $map['features'][] = [
            'type' => 'Feature',
            'properties' => ['kind' => 'building', 'id' => 'b99', 'name' => 'Science Hall', 'type' => 'building', 'floors' => 2, 'landmark_name' => 'the Science Hall'],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[124.7480, 8.5410], [124.7481, 8.5410], [124.7481, 8.5411], [124.7480, 8.5410]]]],
        ];

        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => $this->upload(json_encode($map))])
            ->assertSessionHas('status', 'Added 1, updated 0.');

        $hall = Building::firstWhere('code', 'b99');
        $this->assertSame(['Science Hall', 'the Science Hall'], [$hall->name, $hall->landmark_name]);
    }

    public function test_a_feature_without_a_kind_is_refused_and_the_map_is_left_alone(): void
    {
        $map = json_decode($this->exported(), true);
        unset($map['features'][0]['properties']['kind']);

        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => $this->upload(json_encode($map))])
            ->assertSessionHasErrors(['file' => 'Feature 1 “TCC” needs a kind: building, path, room or stair.']);

        $this->assertSame(17, Building::count());
    }

    public function test_a_file_that_is_not_a_geojson_feature_collection_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => $this->upload('{"buildings": {}}')])
            ->assertSessionHasErrors(['file' => 'The file is not a GeoJSON FeatureCollection.']);

        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => $this->upload('not json')])
            ->assertSessionHasErrors(['file' => 'The file is not valid JSON.']);

        $this->actingAs($this->admin())
            ->post(route('admin.map.import'), ['file' => UploadedFile::fake()->create('campus.json', 10)])
            ->assertSessionHasErrors(['file' => 'The map must be a .geojson file.']);
    }
}
