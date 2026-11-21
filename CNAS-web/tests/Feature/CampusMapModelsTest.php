<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Path;
use App\Models\Room;
use App\Models\Stair;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The campus map tables: buildings, paths, stairs and rooms, each found by its code (b1, p3, s0, r5).
 */
class CampusMapModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_building_with_rooms_cannot_be_deleted(): void
    {
        $building = Building::factory()->create();
        Room::factory()->for($building)->create();

        $this->expectException(QueryException::class);

        $building->delete();
    }

    public function test_models_are_found_by_code_in_urls(): void
    {
        foreach ([new Building, new Path, new Stair, new Room] as $model) {
            $this->assertSame('code', $model->getRouteKeyName(), $model::class);
        }
    }
}
