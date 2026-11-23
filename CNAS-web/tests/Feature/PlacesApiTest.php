<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Services\RecentPlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Saved and recent places for the mobile app (/api/places): the list, save and remove from the navigation card,
 * Recent on Start, and clearing Recent. Same rules as the web (SavedPlacesTest).
 */
class PlacesApiTest extends TestCase
{
    use RefreshDatabase;

    private function room(string $name, int $floor = 1): Room
    {
        return Room::factory()->for(Building::factory()->state(['name' => 'NEE Building', 'landmark_name' => null]))
            ->create(['name' => $name, 'floor' => $floor]);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => UserRole::Student]);
    }

    public function test_it_needs_a_token(): void
    {
        $room = $this->room('ComLab 1');

        $this->getJson(route('api.places'))->assertUnauthorized();
        $this->postJson(route('api.places.save', $room))->assertUnauthorized();
        $this->assertDatabaseCount('saved_places', 0);
    }

    public function test_the_admin_has_no_saved_places(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson(route('api.places'))->assertForbidden();
    }

    public function test_it_lists_saved_and_recent_places_newest_first_with_where_they_are(): void
    {
        $student = $this->student();
        $lab = $this->room('ComLab 1', floor: 2);
        $library = $this->room('Library');
        $this->travel(-1)->minutes();
        $student->savedRooms()->attach($library);
        $this->travelBack();
        $student->savedRooms()->attach($lab);
        app(RecentPlaces::class)->record($student, $library);
        $this->room('Not mine');

        Sanctum::actingAs($student);

        $this->getJson(route('api.places'))
            ->assertOk()
            ->assertExactJson([
                'saved' => [
                    ['code' => $lab->code, 'name' => 'ComLab 1', 'location' => 'NEE Building · 2nd floor'],
                    ['code' => $library->code, 'name' => 'Library', 'location' => 'NEE Building'],
                ],
                'recent' => [
                    ['code' => $library->code, 'name' => 'Library', 'location' => 'NEE Building'],
                ],
            ]);
    }

    public function test_saving_and_removing_a_room(): void
    {
        $student = $this->student();
        $room = $this->room('ComLab 1');
        Sanctum::actingAs($student);

        $this->postJson(route('api.places.save', $room))->assertOk()->assertExactJson(['saved' => true, 'message' => 'ComLab 1 was saved.']);
        $this->postJson(route('api.places.save', $room))->assertOk();
        $this->assertDatabaseCount('saved_places', 1);

        $this->deleteJson(route('api.places.unsave', $room))
            ->assertOk()
            ->assertExactJson(['saved' => false, 'message' => 'ComLab 1 was removed from your saved places.']);
        $this->assertDatabaseCount('saved_places', 0);
    }

    public function test_an_unknown_room_is_not_found(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson(route('api.places.save', 'r404'))->assertNotFound();
    }

    public function test_start_adds_the_room_to_recent_and_recent_can_be_cleared(): void
    {
        $student = $this->student();
        $room = $this->room('ComLab 1');
        Sanctum::actingAs($student);

        $this->postJson(route('api.places.recent', $room))->assertNoContent();
        $this->assertTrue($student->recentRooms()->whereKey($room->id)->exists());

        $this->deleteJson(route('api.places.recent.clear'))->assertOk()->assertExactJson(['message' => 'Recent places cleared.']);
        $this->assertDatabaseCount('recent_places', 0);
    }
}
