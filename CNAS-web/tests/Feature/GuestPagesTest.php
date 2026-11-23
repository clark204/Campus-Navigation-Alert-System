<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Landing, login and register are for guests only: signed-in users go to their role's home.
 */
class GuestPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, string}>
     */
    public static function roles(): array
    {
        return [
            'student' => [UserRole::Student, 'student.dashboard'],
            'faculty' => [UserRole::Faculty, 'faculty.dashboard'],
            'staff' => [UserRole::Staff, 'faculty.dashboard'],
            'admin' => [UserRole::Admin, 'admin.dashboard'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestPages(): array
    {
        return [
            'landing' => ['landing'],
            'login' => ['login'],
            'register' => ['register'],
        ];
    }

    #[DataProvider('roles')]
    public function test_a_signed_in_user_opening_the_landing_page_goes_to_their_home(UserRole $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('landing'))->assertRedirect(route($home));

        // The home page itself loads: no redirect loop.
        $this->actingAs($user)->get(route($home))->assertOk();
    }

    #[DataProvider('roles')]
    public function test_login_and_register_redirect_signed_in_users_home(UserRole $role, string $home): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('login'))->assertRedirect(route($home));
        $this->actingAs($user)->get(route('register'))->assertRedirect(route($home));
    }

    public function test_guests_can_open_the_landing_page(): void
    {
        $this->get(route('landing'))->assertOk()->assertSee('Know your campus.');
    }

    public function test_the_map_is_open_to_everyone(): void
    {
        $this->get(route('navigate'))->assertOk();
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))->get(route('navigate'))->assertOk();
    }

    public function test_the_voice_directions_switch_is_on_navigate_for_guests_and_admins_only(): void
    {
        $this->get(route('navigate'))->assertSee('data-voice-directions', false);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get(route('navigate'))->assertSee('data-voice-directions', false);
        // Students and faculty have it in Settings → Navigation.
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))->get(route('navigate'))->assertDontSee('data-voice-directions', false);
    }

    #[DataProvider('guestPages')]
    public function test_guest_pages_are_never_cached(string $route): void
    {
        $cacheControl = $this->get(route($route))->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
    }
}
