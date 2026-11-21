<?php

namespace Tests\Feature;

use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Path;
use App\Models\Room;
use App\Models\Stair;
use App\Services\CampusMapImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The map import fills the map tables from a folder of GeoJSON (database/data/maps for the seeder). It adds what is
 * new, never deletes, and leaves edits made in the admin pages alone unless told to overwrite.
 */
class CampusMapImporterTest extends TestCase
{
    use RefreshDatabase;

    private function import(?string $directory = null, bool $overwrite = false): array
    {
        return app(CampusMapImporter::class)->import($directory ?? database_path('data/maps'), $overwrite);
    }

    /**
     * A copy of the seed files that a test can change, removed again after the test.
     */
    private function editableCopy(): string
    {
        $directory = storage_path('framework/testing/maps-'.uniqid());
        File::copyDirectory(database_path('data/maps'), $directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        return $directory;
    }

    public function test_the_seed_files_import_every_feature(): void
    {
        $result = $this->import();

        $this->assertSame(['buildings' => 17, 'paths' => 16, 'stairs' => 2, 'rooms' => 29], $result['imported']);
        $this->assertSame([17, 16, 2, 29], [Building::count(), Path::count(), Stair::count(), Room::count()]);

        $this->assertSame('the Library', Building::firstWhere('code', 'b8')->landmark_name);
        $this->assertNull(Building::firstWhere('code', 'b0')->floors);
        $this->assertSame('the main walkway', Path::firstWhere('code', 'p2')->landmark_name);
        $this->assertSame('the Vehicle Gate', Path::firstWhere('code', 'p15')->landmark_name);

        $room = Room::firstWhere('code', 'r0');
        $this->assertSame('HM Hot Kitchen', $room->name);
        $this->assertSame('b2', $room->building->code);
        $this->assertSame(1, $room->floor);
        $this->assertSame(RoomType::Lab, $room->type);
        $this->assertSame([8.54085336, 124.74799236], [$room->latitude, $room->longitude]);

        $stair = Stair::firstWhere('code', 's0');
        $this->assertSame(3, $stair->floors);
        $this->assertSame('b1', $stair->building->code);
        $this->assertSame([8.54102173, 124.74853847], [$stair->center_latitude, $stair->center_longitude]);
    }

    public function test_importing_twice_changes_nothing(): void
    {
        $this->import();

        $result = $this->import();

        $this->assertSame(['buildings' => 0, 'paths' => 0, 'stairs' => 0, 'rooms' => 0], $result['imported']);
        $this->assertSame([17, 16, 2, 29], [Building::count(), Path::count(), Stair::count(), Room::count()]);
    }

    public function test_a_second_import_keeps_admin_edits(): void
    {
        $this->import();
        Building::firstWhere('code', 'b8')->update(['name' => 'Main Library', 'landmark_name' => 'the Main Library']);
        Path::firstWhere('code', 'p2')->update(['landmark_name' => 'the covered walk']);
        Room::firstWhere('code', 'r0')->update(['name' => 'Hot Kitchen', 'latitude' => 8.5409, 'longitude' => 124.7480]);

        $this->import();

        $library = Building::firstWhere('code', 'b8');
        $this->assertSame(['Main Library', 'the Main Library'], [$library->name, $library->landmark_name]);
        $this->assertSame('the covered walk', Path::firstWhere('code', 'p2')->landmark_name);
        $room = Room::firstWhere('code', 'r0');
        $this->assertSame(['Hot Kitchen', 8.5409, 124.7480], [$room->name, $room->latitude, $room->longitude]);
    }

    public function test_a_second_import_refreshes_shapes_from_the_files(): void
    {
        $this->import();
        $directory = $this->editableCopy();
        $square = [[[124.7480, 8.5409], [124.7481, 8.5409], [124.7481, 8.5410], [124.7480, 8.5409]]];
        $buildings = File::json($directory.'/buildings.geojson');
        $buildings['features'][8]['geometry']['coordinates'] = $square;
        $buildings['features'][8]['properties']['name'] = 'Renamed In File';
        File::put($directory.'/buildings.geojson', json_encode($buildings));

        $result = $this->import($directory);

        $library = Building::firstWhere('code', 'b8');
        $this->assertSame($square, $library->outline);
        $this->assertSame('Library Building', $library->name, 'details stay as they are without overwrite');
        $this->assertSame(1, $result['updated']['buildings']);
    }

    public function test_a_paths_floor_comes_from_the_file_for_new_paths_and_with_overwrite(): void
    {
        $directory = $this->editableCopy();
        $paths = File::json($directory.'/paths.geojson');
        $paths['features'][2]['properties']['floor'] = 2;
        unset($paths['features'][3]['properties']['floor']);
        File::put($directory.'/paths.geojson', json_encode($paths));

        $this->import($directory);

        $this->assertSame([2, 1], [Path::firstWhere('code', 'p2')->floor, Path::firstWhere('code', 'p3')->floor], 'no floor in the file means the ground floor');

        Path::firstWhere('code', 'p4')->update(['floor' => 3]);
        $this->import($directory);
        $this->assertSame(3, Path::firstWhere('code', 'p4')->floor, 'kept without overwrite');

        $this->import($directory, overwrite: true);
        $this->assertSame(1, Path::firstWhere('code', 'p4')->floor);
    }

    public function test_overwrite_replaces_details_from_the_files(): void
    {
        $this->import();
        Building::firstWhere('code', 'b8')->update(['name' => 'Main Library', 'landmark_name' => null]);
        Room::firstWhere('code', 'r0')->update(['name' => 'Hot Kitchen', 'latitude' => 8.5409, 'longitude' => 124.7480]);

        $this->import(overwrite: true);

        $library = Building::firstWhere('code', 'b8');
        $this->assertSame(['Library Building', 'the Library'], [$library->name, $library->landmark_name]);
        $room = Room::firstWhere('code', 'r0');
        $this->assertSame(['HM Hot Kitchen', 8.54085336, 124.74799236], [$room->name, $room->latitude, $room->longitude]);
    }

    public function test_rows_missing_from_the_files_are_reported_and_kept(): void
    {
        $this->import();
        Room::factory()->for(Building::firstWhere('code', 'b1'))->create(['code' => 'r99']);

        $result = $this->import();

        $this->assertSame(['buildings' => [], 'paths' => [], 'stairs' => [], 'rooms' => ['r99']], $result['missing']);
        $this->assertTrue(Room::where('code', 'r99')->exists());
    }

    public function test_a_room_in_an_unknown_building_stops_the_whole_import(): void
    {
        $directory = $this->editableCopy();
        $rooms = File::json($directory.'/entrances.geojson');
        $rooms['features'][3]['properties']['building_id'] = 'b404';
        File::put($directory.'/entrances.geojson', json_encode($rooms));

        try {
            $this->import($directory);
            $this->fail('The import should have stopped.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('r3', $exception->getMessage());
            $this->assertStringContainsString('b404', $exception->getMessage());
        }

        $this->assertSame(0, Building::count(), 'nothing is kept from a failed import');
    }

    public function test_a_missing_required_file_is_named(): void
    {
        $directory = $this->editableCopy();
        File::delete($directory.'/paths.geojson');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('paths.geojson');

        $this->import($directory);
    }

    /**
     * Each case changes one seed file into something a wrong export could produce.
     *
     * @return array<string, array{string, callable(array<string, mixed>): array<string, mixed>, list<string>}>
     */
    public static function malformedFiles(): array
    {
        $square = [[124.7480, 8.5409], [124.7481, 8.5409], [124.7481, 8.5410], [124.7480, 8.5409]];

        return [
            'a building exported as a MultiPolygon' => ['buildings.geojson', function (array $file) use ($square): array {
                $file['features'][8]['geometry'] = ['type' => 'MultiPolygon', 'coordinates' => [[$square]]];

                return $file;
            }, ['buildings.geojson', 'b8', 'Polygon']],
            'a building outline that is a bare ring' => ['buildings.geojson', function (array $file) use ($square): array {
                $file['features'][8]['geometry']['coordinates'] = $square;

                return $file;
            }, ['buildings.geojson', 'b8']],
            'a walkway with a single point' => ['paths.geojson', function (array $file): array {
                $file['features'][2]['geometry']['coordinates'] = [[124.7480, 8.5409]];

                return $file;
            }, ['paths.geojson', 'p2']],
            'a walkway on floor 0' => ['paths.geojson', function (array $file): array {
                $file['features'][2]['properties']['floor'] = 0;

                return $file;
            }, ['paths.geojson', 'p2', 'floor']],
            'a walkway floor that is not a number' => ['paths.geojson', function (array $file): array {
                $file['features'][2]['properties']['floor'] = 'second';

                return $file;
            }, ['paths.geojson', 'p2', 'floor']],
            'a room drawn as a line' => ['entrances.geojson', function (array $file): array {
                $file['features'][3]['geometry']['coordinates'] = [[124.7480, 8.5409], [124.7481, 8.5409]];

                return $file;
            }, ['entrances.geojson', 'r3']],
            'a room position that is not a number' => ['entrances.geojson', function (array $file): array {
                $file['features'][3]['geometry']['coordinates'] = ['east', 'north'];

                return $file;
            }, ['entrances.geojson', 'r3']],
            'a stair whose center is not a point' => ['stairs.geojson', function (array $file): array {
                $file['features'][0]['properties']['center'] = [[124.7480, 8.5409]];

                return $file;
            }, ['stairs.geojson', 's0']],
            'a file without a list of features' => ['paths.geojson', function (array $file): array {
                unset($file['features']);

                return $file;
            }, ['paths.geojson']],
            'a file whose features are not a list' => ['paths.geojson', function (array $file): array {
                $file['features'] = 'none';

                return $file;
            }, ['paths.geojson']],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     * @param  list<string>  $mentions
     */
    #[DataProvider('malformedFiles')]
    public function test_a_malformed_file_is_refused_and_the_map_is_left_as_it_was(string $file, callable $break, array $mentions): void
    {
        $this->import();
        $before = [Building::firstWhere('code', 'b8')->outline, Path::count(), Room::firstWhere('code', 'r3')->latitude];
        $directory = $this->editableCopy();
        File::put("{$directory}/{$file}", json_encode($break(File::json("{$directory}/{$file}"))));

        try {
            $this->import($directory, overwrite: true);
            $this->fail('The import should have refused the file.');
        } catch (InvalidArgumentException $exception) {
            foreach ($mentions as $mention) {
                $this->assertStringContainsString($mention, $exception->getMessage());
            }
        }

        $this->assertSame($before, [Building::firstWhere('code', 'b8')->outline, Path::count(), Room::firstWhere('code', 'r3')->latitude]);
    }

    public function test_stairs_and_landmark_names_are_optional_files(): void
    {
        $directory = $this->editableCopy();
        File::delete([$directory.'/stairs.geojson', $directory.'/landmarks.json']);

        $result = $this->import($directory);

        $this->assertSame(0, $result['imported']['stairs']);
        $this->assertSame(29, Room::count());
        $this->assertNull(Building::firstWhere('code', 'b8')->landmark_name);
    }

    public function test_the_command_prints_the_report(): void
    {
        $this->artisan('map:import', ['directory' => database_path('data/maps')])
            ->expectsOutputToContain('29 rooms')
            ->assertSuccessful();

        $this->assertSame(29, Room::count());
    }

    public function test_the_command_fails_with_the_reason(): void
    {
        $this->artisan('map:import', ['directory' => storage_path('framework/testing/no-such-folder')])
            ->expectsOutputToContain('buildings.geojson')
            ->assertFailed();
    }

    public function test_the_database_seeder_fills_the_map(): void
    {
        $this->seed();

        $this->assertSame(29, Room::count());
    }
}
