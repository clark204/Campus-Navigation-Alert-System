<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin.dashboard'])]
    #[TestWith(['admin.academic-terms.index'])]
    public function test_guests_are_sent_to_sign_in(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[TestWith(['admin.dashboard'])]
    #[TestWith(['admin.academic-terms.index'])]
    public function test_non_admins_are_forbidden(string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route($route))
            ->assertForbidden();
    }

    public function test_non_admins_cannot_change_terms(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))
            ->post(route('admin.academic-terms.store'), ['academic_year' => '2026-2027', 'semester' => '2nd'])
            ->assertForbidden();

        $this->assertDatabaseCount('academic_terms', 0);
    }

    public function test_admins_get_in(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Campus overview');
    }
}
