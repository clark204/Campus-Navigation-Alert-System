<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Path;
use App\Models\Room;
use App\Models\Stair;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A campus.geojson saved by JOSM: closed shapes as LineStrings, every value as text, rooms stacked on one spot as one
 * feature, and features drawn without a code. The fixture (tests/Fixtures/josm/campus.geojson) has each case once.
 */
class CampusMapJosmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CampusMapSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/josm/campus.geojson')), true);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function import(array $document): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('campus.geojson', json_encode($document));

        return $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post(route('admin.map.import'), ['file' => $file]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  callable(array<string, mixed>): array<string, mixed>  $change  the feature to change, by its name
     */
    private function editFeature(array &$document, string $name, callable $change): void
    {
        foreach ($document['features'] as $index => $feature) {
            if ($feature['properties']['name'] === $name) {
                $document['features'][$index] = $change($feature);

                return;
            }
        }
        $this->fail("No feature named {$name} in the fixture.");
    }

    public function test_the_josm_fixture_imports_and_reports_every_case_by_name(): void
    {
        // Two rooms with the same name, so a feature drawn with that name is ambiguous.
        Room::query()->whereIn('code', ['r0', 'r1'])->update(['name' => 'Shared Room']);

        $this->import($this->fixture())
            ->assertRedirect(route('admin.map.index'))
            ->assertSessionHas('importReport', function (array $report): bool {
                return $report['matched'] === [['kind' => 'Path', 'name' => 'Walkway 7', 'code' => 'p7']]
                    && $report['duplicates'] === [['kind' => 'Room', 'name' => 'Shared Room', 'code' => 'r29']]
                    && in_array(['kind' => 'Path', 'name' => 'Gate Lane', 'code' => 'p16'], $report['added'], true)
                    && in_array(['kind' => 'Building', 'name' => 'Library Building', 'code' => 'b8'], $report['updated'], true);
            });
    }

    public function test_a_closed_line_becomes_the_building_outline(): void
    {
        $this->import($this->fixture());

        $outline = Building::firstWhere('code', 'b8')->outline;
        $this->assertCount(1, $outline);
        $this->assertEquals([124.7485, 8.5414], $outline[0][2]);
    }

    public function test_text_values_become_numbers_objects_and_real_booleans(): void
    {
        Room::firstWhere('code', 'r19')->update(['is_it' => true]);

        $this->import($this->fixture());

        $this->assertSame(3, Building::firstWhere('code', 'b8')->floors);
        $stair = Stair::firstWhere('code', 's0');
        $this->assertSame([3, 124.74853847, 8.54102173], [$stair->floors, $stair->center_longitude, $stair->center_latitude]);
        // "false" stays false: a plain (bool) cast would have read it as true.
        $this->assertFalse(Room::firstWhere('code', 'r19')->is_it);
    }

    public function test_stacked_rooms_move_together_and_keep_their_own_details(): void
    {
        $this->import($this->fixture());

        foreach (['r16' => [1, 'NEE 101'], 'r17' => [2, 'NEE 201'], 'r18' => [3, 'NEE 301']] as $code => [$floor, $name]) {
            $room = Room::firstWhere('code', $code);
            $this->assertEquals([124.7486, 8.5411], [$room->longitude, $room->latitude], $code);
            $this->assertSame([$name, $floor], [$room->name, $room->floor], $code);
        }
    }

    public function test_a_drawn_feature_with_a_known_name_reuses_its_code(): void
    {
        $paths = Path::count();

        $this->import($this->fixture())
            ->assertSessionHas('status', 'Added 2, updated 7. 57 are kept because not in the file.');

        // "  walkway   7 " is Walkway 7 (ignoring capitals and spaces), so it keeps p7 and no new path is made.
        $this->assertSame([124.74825093, 8.5411886], Path::firstWhere('code', 'p7')->line[0]);
        $this->assertSame($paths + 1, Path::count());
    }

    public function test_a_drawn_feature_with_a_new_name_gets_the_next_free_code(): void
    {
        $this->import($this->fixture());

        $lane = Path::firstWhere('code', 'p16');
        $this->assertSame(['Gate Lane', 'walkway', 1], [$lane->name, $lane->type->value, $lane->floor]);
    }

    public function test_an_ambiguous_name_is_added_as_new_and_flagged(): void
    {
        Room::query()->whereIn('code', ['r0', 'r1'])->update(['name' => 'Shared Room']);

        $this->import($this->fixture());

        $this->assertSame('Shared Room', Room::firstWhere('code', 'r29')->name);
        $this->assertSame(['r0', 'r1'], Room::where('name', 'Shared Room')->where('code', '!=', 'r29')->orderBy('code')->pluck('code')->all());
    }

    public function test_the_map_page_lists_the_result_by_name(): void
    {
        Room::query()->whereIn('code', ['r0', 'r1'])->update(['name' => 'Shared Room']);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $file = UploadedFile::fake()->createWithContent('campus.geojson', json_encode($this->fixture()));
        $this->actingAs($admin)->post(route('admin.map.import'), ['file' => $file]);

        $this->actingAs($admin)->get(route('admin.map.index'))
            ->assertOk()
            ->assertSee('What the import changed')
            ->assertSee('Matched by name and redrawn')
            ->assertSee('Possible duplicates')
            ->assertSee('Gate Lane')
            ->assertSee('Path · p16');
    }

    public function test_a_stacked_room_that_is_not_on_the_map_is_refused_and_nothing_changes(): void
    {
        $document = $this->fixture();
        $this->editFeature($document, 'NEE 101;NEE 201;NEE 301', fn (array $feature) => [
            ...$feature, 'properties' => [...$feature['properties'], 'id' => 'r16;r99'],
        ]);

        $before = Room::firstWhere('code', 'r16')->only(['longitude', 'latitude']);

        $this->import($document)->assertSessionHasErrors(['file' => 'Feature 2 “NEE 101;NEE 201;NEE 301”: stacked rooms must be added one at a time. r99 is not on the map yet.']);

        $this->assertSame($before, Room::firstWhere('code', 'r16')->only(['longitude', 'latitude']));
    }

    public function test_an_outline_that_is_not_closed_is_refused_naming_the_feature(): void
    {
        $document = $this->fixture();
        $this->editFeature($document, 'Library Building', function (array $feature) {
            $feature['geometry']['coordinates'][4] = [124.7480, 8.5415];

            return $feature;
        });

        $this->import($document)->assertSessionHasErrors(['file' => 'Feature 1 “Library Building” is not a closed shape: join its last point to its first point in JOSM (it needs at least 3 corners).']);
    }

    public function test_a_value_that_is_not_valid_is_refused_naming_the_feature(): void
    {
        $document = $this->fixture();
        $this->editFeature($document, '  walkway   7 ', function (array $feature) {
            $feature['properties']['floor'] = 'abc';

            return $feature;
        });

        $this->import($document)->assertSessionHasErrors(['file' => 'Feature 5 “walkway   7”: floor must be a whole number from 1 to 10 (it is “abc”).']);
    }

    public function test_is_it_must_be_true_or_false(): void
    {
        $document = $this->fixture();
        $this->editFeature($document, 'NEE 102', function (array $feature) {
            $feature['properties']['is_it'] = 'yes';

            return $feature;
        });

        $this->import($document)->assertSessionHasErrors(['file' => 'Feature 3 “NEE 102”: is_it must be true or false (it is “yes”).']);
    }
}
