<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\Building;
use App\Models\Panorama;
use App\Models\Path;
use App\Models\Room;
use App\Models\User;
use App\Services\CampusMapImporter;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * GET /api/map is the one place every map (web pages and the mobile app) reads the campus from. It serves the map
 * tables in the same GeoJSON shape as the seed files in database/data/maps, which the JS routing tests run on.
 */
class MapApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, mixed>> the file's features by id
     */
    private function fileFeatures(string $file): array
    {
        return collect(File::json(database_path("data/maps/{$file}"))['features'])->keyBy('properties.id')->all();
    }

    /**
     * @return list<string> the names of the rooms the API lists
     */
    private function roomNames(): array
    {
        return $this->getJson(route('api.map'))->assertOk()->json('data.entrances.features.*.properties.name');
    }

    public function test_the_map_matches_the_seed_files(): void
    {
        $this->seed(CampusMapSeeder::class);

        $data = $this->getJson(route('api.map'))->assertOk()->json('data');

        foreach (['buildings' => 'buildings.geojson', 'paths' => 'paths.geojson', 'entrances' => 'entrances.geojson', 'stairs' => 'stairs.geojson'] as $key => $file) {
            $this->assertSame('FeatureCollection', $data[$key]['type']);
            // Photos are not in the seed files; the photo tests cover them.
            $features = collect($data[$key]['features'])
                ->map(fn (array $feature) => [...$feature, 'properties' => Arr::except($feature['properties'], ['photos'])]);
            $this->assertEquals($this->fileFeatures($file), $features->keyBy('properties.id')->all(), $file);
        }

        $landmarks = File::json(database_path('data/maps/landmarks.json'));
        unset($landmarks['_readme']);
        $this->assertEquals($landmarks, $data['landmarks']);
    }

    public function test_features_are_listed_in_code_order(): void
    {
        $this->seed(CampusMapSeeder::class);

        $data = $this->getJson(route('api.map'))->json('data');

        $this->assertSame(array_map(fn (int $number) => "b{$number}", range(0, 16)), array_column(array_column($data['buildings']['features'], 'properties'), 'id'));
        $this->assertSame(array_map(fn (int $number) => "r{$number}", range(0, 28)), array_column(array_column($data['entrances']['features'], 'properties'), 'id'));
    }

    public function test_each_path_gives_its_floor(): void
    {
        Path::factory()->create(['code' => 'p1']);
        Path::factory()->onFloor(2)->create(['code' => 'p2']);

        $this->getJson(route('api.map'))
            ->assertOk()
            ->assertJsonPath('data.paths.features.0.properties.floor', 1)
            ->assertJsonPath('data.paths.features.1.properties.floor', 2);
    }

    public function test_an_empty_map_returns_empty_collections(): void
    {
        $response = $this->getJson(route('api.map'))->assertOk();

        foreach (['buildings', 'paths', 'entrances', 'stairs'] as $key) {
            $response->assertJsonPath("data.{$key}", ['type' => 'FeatureCollection', 'features' => []]);
        }
        $this->assertStringContainsString('"landmarks":{"buildings":{},"paths":{}}', $response->getContent());
    }

    public function test_the_map_updates_after_a_room_is_added_changed_and_removed(): void
    {
        $building = Building::factory()->create();
        $this->assertSame([], $this->roomNames());

        $room = Room::factory()->for($building)->create(['name' => 'Speech Lab']);
        $this->assertSame(['Speech Lab'], $this->roomNames());

        $room->update(['name' => 'Language Lab']);
        $this->assertSame(['Language Lab'], $this->roomNames());

        $room->delete();
        $this->assertSame([], $this->roomNames());
    }

    public function test_the_map_updates_after_an_import(): void
    {
        $this->assertSame([], $this->roomNames());

        app(CampusMapImporter::class)->import(database_path('data/maps'));

        $this->assertCount(29, $this->roomNames());
    }

    public function test_a_blank_landmark_name_is_left_out_so_directions_do_not_mention_it(): void
    {
        Building::factory()->create(['code' => 'b1', 'landmark_name' => 'the NEE Building']);
        Building::factory()->create(['code' => 'b2', 'landmark_name' => null]);

        $this->getJson(route('api.map'))->assertJsonPath('data.landmarks.buildings', ['b1' => 'the NEE Building']);
    }

    public function test_an_unchanged_map_answers_not_modified(): void
    {
        $this->seed(CampusMapSeeder::class);

        $etag = $this->getJson(route('api.map'))->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->getJson(route('api.map'), ['If-None-Match' => $etag])->assertStatus(304);

        Room::firstWhere('code', 'r0')->update(['name' => 'Renamed']);
        $this->getJson(route('api.map'), ['If-None-Match' => $etag])->assertOk();
    }

    public function test_alerts_can_still_be_sent_with_an_empty_map(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('admin.alerts.create'))->assertOk();

        $this->actingAs($admin)->post(route('admin.alerts.store'), [
            'type' => 'typhoon',
            'severity' => 'warning',
            'title' => 'Typhoon signal raised',
            'message' => 'Classes are suspended.',
            'latitude' => '',
            'longitude' => '',
            'building_codes' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Whole campus', Alert::sole()->locationLabel());
    }

    public function test_only_photos_shown_to_users_are_sent_with_links_on_this_site(): void
    {
        $shown = Panorama::factory()->visible(287.5)->create([
            'code' => 'pn3', 'name' => 'Main walkway', 'floor' => 1, 'latitude' => 8.541122, 'longitude' => 124.7483552,
            'image_path' => 'panoramas/pn3-abc.jpg', 'thumb_path' => 'panoramas/pn3-abc-thumb.jpg',
        ]);
        Panorama::factory()->create(['code' => 'pn1']);
        Panorama::factory()->withNorth()->create(['code' => 'pn2']);

        $panoramas = $this->getJson(route('api.map'))->assertOk()->json('data.panoramas');

        $this->assertSame('FeatureCollection', $panoramas['type']);
        $this->assertEquals([[
            'type' => 'Feature',
            'properties' => [
                'id' => 'pn3',
                'name' => 'Main walkway',
                'floor' => 1,
                'north_offset' => 287.5,
                'image' => '/storage/panoramas/pn3-abc.jpg',
                'thumb' => '/storage/panoramas/pn3-abc-thumb.jpg',
            ],
            'geometry' => ['type' => 'Point', 'coordinates' => [124.7483552, 8.541122]],
        ]], $panoramas['features']);

        $shown->update(['is_visible' => false]);
        $this->assertSame([], $this->getJson(route('api.map'))->json('data.panoramas.features'));
    }
}
