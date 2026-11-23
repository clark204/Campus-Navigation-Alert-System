<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The top bar's breadcrumb trail (partials/app-breadcrumbs): home › the sidebar item › the page's own steps, the last
 * one being the current page, in the admin console and the portal.
 */
class BreadcrumbsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_edit_page_shows_home_its_section_and_itself(): void
    {
        $this->seed(CampusMapSeeder::class);
        $room = Room::query()->where('code', 'r0')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.rooms.edit', $room))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Breadcrumb"',
                'href="'.route('admin.dashboard').'"', 'Overview',
                'href="'.route('admin.rooms.index').'"', 'Rooms',
                'aria-current="page"', e($room->name),
                '</nav>',
            ], false);
    }

    public function test_the_home_page_is_the_only_step_on_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Breadcrumb"', 'aria-current="page"', 'Overview', '</nav>'], false)
            ->assertDontSee('aria-label="Back to', false);
    }

    public function test_a_portal_page_three_steps_deep_links_back_to_its_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))
            ->get(route('faculty.classes.create'))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Breadcrumb"',
                'aria-label="Back to My classes"',
                'href="'.route('faculty.dashboard').'"', 'Home',
                'href="'.route('faculty.classes.index').'"', 'My classes',
                'aria-current="page"', 'Add a class',
                '</nav>',
            ], false);
    }

    public function test_a_page_outside_the_sidebar_shows_its_heading_after_home(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.help'))
            ->assertOk()
            ->assertSeeInOrder([
                'aria-label="Breadcrumb"',
                'href="'.route('student.dashboard').'"', 'Home',
                'aria-current="page"', 'Help &amp; contact',
                '</nav>',
            ], false);
    }
}
