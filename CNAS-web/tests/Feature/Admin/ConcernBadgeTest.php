<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Concern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar's open concerns badge, refreshed every 30 s on admin pages (admin.concerns.count, admin-badges.js).
 */
class ConcernBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_gets_the_open_count_and_the_sidebar_can_refresh_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Concern::factory()->count(2)->create();
        Concern::factory()->create(['resolved_at' => now()]);

        $this->actingAs($admin)->getJson(route('admin.concerns.count'))
            ->assertOk()
            ->assertExactJson(['count' => 2])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('data-live-badge="'.route('admin.concerns.count').'"', false);
    }

    public function test_others_get_no_count(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))->getJson(route('admin.concerns.count'))->assertForbidden();
    }
}
