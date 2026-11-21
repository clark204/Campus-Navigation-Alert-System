<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use App\Services\RecentPlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saved and recent places: "Save" and Start on /navigate (PlaceController), the portal's Saved places page and the
 * Saved and Recent lists on the portal home.
 */
class SavedPlacesTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => UserRole::Student]);
    }

    private function room(string $name, int $floor = 1, string $building = 'NEE Building'): Room
    {
        return Room::factory()->for(Building::factory()->state(['name' => $building, 'landmark_name' => null]))
            ->create(['name' => $name, 'floor' => $floor]);
    }

    public function test_guests_cannot_save_places(): void
    {
        $room = $this->room('ComLab 1');

        $this->postJson(route('places.save', $room))->assertUnauthorized();
        $this->post(route('places.recent', $room))->assertRedirect(route('login'));

        $this->assertDatabaseCount('saved_places', 0);
        $this->assertDatabaseCount('recent_places', 0);
    }

    public function test_saving_a_room_from_the_map_keeps_it_once(): void
    {
        $student = $this->student();
        $room = $this->room('ComLab 1');

        $this->actingAs($student)->postJson(route('places.save', $room))
            ->assertOk()
            ->assertExactJson(['saved' => true, 'message' => 'ComLab 1 was saved.']);
        $this->actingAs($student)->postJson(route('places.save', $room))->assertOk();

        $this->assertSame([$room->id], $student->savedRooms()->pluck('rooms.id')->all());
    }

    public function test_an_unknown_room_code_returns_404(): void
    {
        $this->actingAs($this->student())->postJson(route('places.save', ['room' => 'r-nope']))->assertNotFound();
    }

    public function test_removing_a_saved_room_from_the_saved_places_page_goes_back_with_a_message(): void
    {
        $student = $this->student();
        $room = $this->room('ComLab 1');
        $other = $this->room('ComLab 2');
        $student->savedRooms()->attach([$room->id, $other->id]);

        $this->actingAs($student)
            ->from(route('student.saved'))
            ->delete(route('places.unsave', $room))
            ->assertRedirect(route('student.saved'))
            ->assertSessionHas('status', 'ComLab 1 was removed from your saved places.');

        $this->assertSame([$other->id], $student->savedRooms()->pluck('rooms.id')->all());
    }

    public function test_start_puts_a_room_at_the_top_of_recent_without_duplicates(): void
    {
        $student = $this->student();
        [$first, $second] = [$this->room('ComLab 1'), $this->room('ComLab 2')];

        $this->travelTo('2026-11-18 08:00');
        $this->actingAs($student)->postJson(route('places.recent', $first))->assertNoContent();
        $this->travelTo('2026-11-18 09:00');
        $this->actingAs($student)->postJson(route('places.recent', $second))->assertNoContent();
        $this->travelTo('2026-11-18 10:00');
        $this->actingAs($student)->postJson(route('places.recent', $first))->assertNoContent();

        $this->assertSame(['ComLab 1', 'ComLab 2'], $student->recentRooms()->pluck('name')->all());
    }

    public function test_only_the_newest_recent_places_are_kept(): void
    {
        $student = $this->student();
        $rooms = collect(range(1, RecentPlaces::KEEP + 2))->map(fn (int $i) => $this->room("Room {$i}"));

        foreach ($rooms as $i => $room) {
            $this->travelTo(now()->addMinute());
            $this->actingAs($student)->postJson(route('places.recent', $room))->assertNoContent();
        }

        $this->assertDatabaseCount('recent_places', RecentPlaces::KEEP);
        $this->assertSame(
            $rooms->reverse()->take(RecentPlaces::KEEP)->pluck('name')->values()->all(),
            $student->recentRooms()->pluck('name')->all(),
        );
    }

    public function test_clearing_recent_places_keeps_saved_ones_and_other_users_history(): void
    {
        $student = $this->student();
        $other = $this->student();
        $room = $this->room('ComLab 1');
        $student->savedRooms()->attach($room->id);
        $student->recentRooms()->attach($room->id, ['used_at' => now()]);
        $other->recentRooms()->attach($room->id, ['used_at' => now()]);

        $this->actingAs($student)
            ->from(route('student.saved'))
            ->delete(route('places.recent.clear'))
            ->assertRedirect(route('student.saved'))
            ->assertSessionHas('status', 'Recent places cleared.');

        $this->assertSame(0, $student->recentRooms()->count());
        $this->assertSame(1, $student->savedRooms()->count());
        $this->assertSame(1, $other->recentRooms()->count());
    }

    public function test_the_saved_places_page_lists_only_the_users_own_places(): void
    {
        $student = $this->student();
        $mine = $this->room('NEE 201', floor: 2);
        $theirs = $this->room('Registrar Window');
        $student->savedRooms()->attach($mine->id);
        $this->student()->savedRooms()->attach($theirs->id);

        $this->actingAs($student)
            ->get(route('student.saved'))
            ->assertOk()
            ->assertSee('NEE 201')
            ->assertSee('NEE Building · 2nd floor')
            ->assertSee(route('navigate', ['to' => $mine->code]), false)
            ->assertSee('Remove NEE 201 from saved places')
            ->assertDontSee('Registrar Window');
    }

    public function test_the_saved_places_page_explains_how_to_save_when_empty(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.saved'))
            ->assertOk()
            ->assertSee('Nothing saved yet. To save a place:')
            ->assertSee('Places you start directions to show up here.');
    }

    public function test_the_home_page_lists_the_users_saved_and_recent_places(): void
    {
        $faculty = User::factory()->create(['role' => UserRole::Faculty]);
        $saved = $this->room('IT Faculty Office');
        $recent = $this->room('ComLab 4');
        $faculty->savedRooms()->attach($saved->id);
        $faculty->recentRooms()->attach($recent->id, ['used_at' => now()]);

        $this->actingAs($faculty)
            ->get(route('faculty.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Saved', 'IT Faculty Office', 'Recent', 'ComLab 4'])
            ->assertSee(route('faculty.saved'), false);
    }

    public function test_the_home_page_says_how_to_save_when_nothing_is_saved(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('No saved places yet. Open a room on the campus map and tap Save to keep it here.')
            ->assertSee('Places you start directions to show up here, so you can go back with one tap.');
    }

    public function test_the_map_offers_save_to_signed_in_users_with_their_saved_rooms(): void
    {
        $student = $this->student();
        $room = $this->room('ComLab 1');
        $student->savedRooms()->attach($room->id);

        $this->actingAs($student)
            ->get(route('navigate'))
            ->assertOk()
            ->assertSee('data-save-place', false)
            ->assertSee("data-saved='[\"{$room->code}\"]'", false);
    }

    public function test_the_map_has_no_save_button_for_guests(): void
    {
        $this->get(route('navigate'))->assertOk()->assertDontSee('data-save-place', false);
    }
}
