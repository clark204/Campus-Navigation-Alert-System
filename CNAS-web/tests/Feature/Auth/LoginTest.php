<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_signs_in_with_their_school_id(): void
    {
        $user = User::factory()->create(['school_id' => '20240001', 'role' => UserRole::Student]);

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'password'])
            ->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_can_sign_in_with_their_email(): void
    {
        $user = User::factory()->create(['email' => 'juan@tcc.edu.ph', 'role' => UserRole::Admin]);

        $this->post(route('login.store'), ['login' => 'Juan@TCC.edu.ph', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_sets_the_remember_cookie(): void
    {
        $user = User::factory()->create(['school_id' => '20240001', 'role' => UserRole::Student]);

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('student.dashboard'))
            ->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_an_unknown_login_is_rejected(): void
    {
        $this->post(route('login.store'), ['login' => 'nobody@tcc.edu.ph', 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['school_id' => '20240001']);

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'wrong'])
            ->assertSessionHasErrors(['login' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_a_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('landing'));

        $this->assertGuest();
    }

    public function test_sign_in_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['login' => '20240001', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'wrong'])
            ->assertSessionHasErrors('login');
        $this->assertStringStartsWith('Too many sign-in attempts.', session('errors')->first('login'));
    }
}
