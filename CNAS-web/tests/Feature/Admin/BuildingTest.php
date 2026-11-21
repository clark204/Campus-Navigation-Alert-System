<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\PlacePhoto;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin "Buildings": the list of real places, and the edit page where only the name and landmark name change.
 */
class BuildingTest extends TestCase
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

    public function test_only_admins_can_open_the_buildings(): void
    {
        $this->get(route('admin.buildings.index'))->assertRedirect(route('login'));

        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->actingAs($student)->get(route('admin.buildings.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.buildings.edit', $this->library))->assertForbidden();
        $this->actingAs($student)->put(route('admin.buildings.update', $this->library), ['name' => 'Hacked'])->assertForbidden();
    }

    public function test_the_edit_page_shows_the_code_and_lets_the_name_and_landmark_be_changed(): void
    {
        $this->actingAs($this->admin())->get(route('admin.buildings.edit', $this->library))
            ->assertOk()
            ->assertSee('b8')
            ->assertSee('value="Library Building"', false)
            ->assertSee('value="the Library"', false)
            ->assertSee('name="landmark_name"', false)
            ->assertDontSee('name="code"', false)
            ->assertDontSee('name="floors"', false);
    }

    public function test_saving_changes_the_name_and_landmark_name_only(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.buildings.update', $this->library), [
                'name' => 'Main Library', 'landmark_name' => 'the Main Library', 'floors' => 9, 'type' => 'facility',
            ])
            ->assertRedirect(route('admin.buildings.index'))
            ->assertSessionHas('status', 'Main Library was saved.');

        $this->library->refresh();
        $this->assertSame(['Main Library', 'the Main Library'], [$this->library->name, $this->library->landmark_name]);
        $this->assertSame([3, 'building'], [$this->library->floors, $this->library->type->value]);
    }

    public function test_the_landmark_name_can_be_cleared(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.buildings.update', $this->library), ['name' => 'Library Building', 'landmark_name' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->library->refresh()->landmark_name);
    }

    public function test_the_name_is_required_and_has_length_limits(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.buildings.update', $this->library), ['name' => '', 'landmark_name' => 'the Library'])
            ->assertSessionHasErrors(['name' => 'Enter the name students see.']);

        $this->actingAs($this->admin())
            ->put(route('admin.buildings.update', $this->library), ['name' => str_repeat('a', 101)])
            ->assertSessionHasErrors(['name']);

        $this->actingAs($this->admin())
            ->put(route('admin.buildings.update', $this->library), ['name' => 'Library', 'landmark_name' => str_repeat('b', 61)])
            ->assertSessionHasErrors(['landmark_name']);

        $this->assertSame('Library Building', $this->library->refresh()->name);
    }

    public function test_the_campus_outline_has_no_edit_page(): void
    {
        $campus = Building::firstWhere('code', 'b0');

        $this->actingAs($this->admin())->get(route('admin.buildings.edit', $campus))->assertNotFound();
        $this->actingAs($this->admin())->put(route('admin.buildings.update', $campus), ['name' => 'Renamed'])->assertNotFound();
    }

    public function test_the_list_holds_every_real_place_for_the_browser_to_search_and_page(): void
    {
        PlacePhoto::factory()->for($this->library, 'place')->create([
            'image_path' => 'place-photos/cover.jpg', 'thumb_path' => 'place-photos/cover-thumb.jpg',
        ]);

        $this->actingAs($this->admin())->get(route('admin.buildings.index'))
            ->assertOk()
            ->assertViewHas('buildings', fn (Collection $buildings) => $buildings->count() === 16)
            ->assertSee('data-table-search-root', false)
            ->assertSee('data-page-size="10"', false)
            ->assertSee('data-table-search', false)
            // Each row carries what the search matches: name, code, direction name and type.
            ->assertSee('data-search="library building b8 the library building"', false)
            ->assertSee('src="/storage/place-photos/cover-thumb.jpg"', false)
            // Hovering the cover shows the full photo, not the thumbnail.
            ->assertSee('data-still="/storage/place-photos/cover.jpg"', false)
            ->assertSee('data-still-list', false)
            ->assertSee(route('admin.buildings.edit', $this->library))
            // The campus outline is not a place.
            ->assertDontSee(route('admin.buildings.edit', Building::firstWhere('code', 'b0')));
    }

    public function test_the_list_has_no_server_search_or_page_links(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.buildings.index', ['q' => 'library']))
            ->assertOk()
            ->assertViewHas('buildings', fn (Collection $buildings) => $buildings->count() === 16)
            ->assertDontSee('Clear', false);

        // The top bar's site-wide search has a q field; the list itself must not.
        $this->assertStringNotContainsString('name="q"', Str::between($response->getContent(), '<main', '</main>'));
    }
}
