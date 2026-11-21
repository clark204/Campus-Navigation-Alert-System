<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\PlacePhoto;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin "Rooms": every room with its building, filterable by building, and an edit page where only the name changes.
 */
class RoomTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CampusMapSeeder::class);
        $this->room = Room::query()->where('code', 'r0')->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_only_admins_can_open_the_rooms(): void
    {
        $this->get(route('admin.rooms.index'))->assertRedirect(route('login'));

        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->actingAs($student)->get(route('admin.rooms.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.rooms.edit', $this->room))->assertForbidden();
        $this->actingAs($student)->put(route('admin.rooms.update', $this->room), ['name' => 'Hacked'])->assertForbidden();
    }

    public function test_the_list_shows_each_room_with_its_building_floor_type_and_photo_count(): void
    {
        PlacePhoto::factory()->for($this->room, 'place')->create();

        $this->actingAs($this->admin())->get(route('admin.rooms.index', ['building' => $this->room->building->code]))
            ->assertOk()
            ->assertSee($this->room->name)
            ->assertSee($this->room->building->name)
            ->assertSee($this->room->type->label())
            ->assertSee(route('admin.rooms.edit', $this->room));
    }

    public function test_the_building_filter_lists_only_that_buildings_rooms(): void
    {
        $inBuilding = Room::with('building')->firstOrFail();
        $otherRoom = Room::query()->where('building_id', '!=', $inBuilding->building_id)->firstOrFail();

        $this->actingAs($this->admin())->get(route('admin.rooms.index', ['building' => $inBuilding->building->code]))
            ->assertOk()
            ->assertSee(route('admin.rooms.edit', $inBuilding))
            ->assertDontSee(route('admin.rooms.edit', $otherRoom));
    }

    public function test_the_edit_page_shows_the_code_and_place_and_lets_only_the_name_change(): void
    {
        $this->actingAs($this->admin())->get(route('admin.rooms.edit', $this->room))
            ->assertOk()
            ->assertSee('r0')
            ->assertSee($this->room->building->name)
            ->assertSee('value="'.$this->room->name.'"', false)
            ->assertDontSee('name="code"', false)
            ->assertDontSee('name="floor"', false);
    }

    public function test_saving_changes_the_name_only(): void
    {
        $floor = $this->room->floor;
        $this->actingAs($this->admin())
            ->put(route('admin.rooms.update', $this->room), ['name' => 'ComLab 1 (Ground)', 'floor' => 4, 'type' => 'office', 'building_id' => 1])
            ->assertRedirect(route('admin.rooms.index'))
            ->assertSessionHas('status', 'ComLab 1 (Ground) was saved.');

        $this->room->refresh();
        $this->assertSame('ComLab 1 (Ground)', $this->room->name);
        $this->assertSame($floor, $this->room->floor);
    }

    public function test_the_name_is_required_and_has_a_length_limit(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.rooms.update', $this->room), ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Enter the name students see.']);

        $this->actingAs($this->admin())
            ->put(route('admin.rooms.update', $this->room), ['name' => str_repeat('r', 101)])
            ->assertSessionHasErrors(['name']);

        $this->assertNotSame('', $this->room->refresh()->name);
    }

    public function test_the_list_holds_every_room_for_the_browser_to_search_and_page(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.rooms.index'))
            ->assertOk()
            ->assertViewHas('rooms', fn (Collection $rooms) => $rooms->count() === Room::count())
            ->assertSee('data-table-search-root', false)
            ->assertSee('data-page-size="10"', false)
            ->assertSee('data-search="'.strtolower($this->room->name.' '.$this->room->code.' '.$this->room->building->name.' '.$this->room->type->label()).'"', false);

        // The top bar's site-wide search has a q field; the list itself must not.
        $this->assertStringNotContainsString('name="q"', Str::between($response->getContent(), '<main', '</main>'));
    }
}
