<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flash messages show as toasts (partials/toasts) in the portal, admin and sign-in layouts, not as boxes on the page.
 */
class ToastsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_status_message_is_a_toast_that_closes_by_itself(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->withSession(['status' => 'Building saved.'])
            ->get(route('admin.buildings.index'))
            ->assertSeeInOrder(['data-toast-region', 'data-tone="success"', 'Building saved.', 'data-toast-timer'], escape: false);
    }

    public function test_a_warning_is_a_toast_that_stays_until_closed(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->withSession(['warning' => 'There is no current term yet.'])
            ->get(route('student.schedule.index'))
            ->assertSeeInOrder(['data-tone="warning"', 'There is no current term yet.'], escape: false);

        $this->assertStringNotContainsString('data-toast-timer', $response->getContent());
    }

    public function test_the_sign_in_page_shows_its_message_as_a_toast(): void
    {
        $this->withSession(['status' => 'You are signed out.'])
            ->get(route('login'))
            ->assertSeeInOrder(['data-toast-region', 'You are signed out.'], escape: false);
    }
}
