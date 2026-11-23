<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, string, string}>
     */
    public static function rolesThatFirstTriedAnotherPage(): array
    {
        return [
            'student who tried the admin console' => [UserRole::Student, 'admin.dashboard', 'student.dashboard'],
            'faculty who tried the student portal' => [UserRole::Faculty, 'student.dashboard', 'faculty.dashboard'],
            'staff who tried an admin page' => [UserRole::Staff, 'admin.settings', 'faculty.dashboard'],
            'admin who tried the faculty portal' => [UserRole::Admin, 'faculty.dashboard', 'admin.dashboard'],
            'admin who tried another admin page' => [UserRole::Admin, 'admin.settings', 'admin.dashboard'],
        ];
    }

    #[DataProvider('rolesThatFirstTriedAnotherPage')]
    public function test_signing_in_always_lands_on_the_roles_own_dashboard(UserRole $role, string $triedRoute, string $home): void
    {
        User::factory()->create(['school_id' => '20240001', 'role' => $role]);

        $this->get(route($triedRoute))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'password'])
            ->assertRedirect(route($home));
    }

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

    public function test_an_unknown_login_gets_the_same_generic_message(): void
    {
        $this->post(route('login.store'), ['login' => 'nobody@tcc.edu.ph', 'password' => 'password'])
            ->assertSessionHasErrors(['password' => 'Invalid credentials.'])
            ->assertSessionDoesntHaveErrors('login');

        $this->assertGuest();
    }

    public function test_a_wrong_password_shows_invalid_credentials_under_the_password_and_marks_both_fields(): void
    {
        User::factory()->create(['school_id' => '20240001']);

        $html = $this->from(route('login'))->followingRedirects()
            ->post(route('login.store'), ['login' => '20240001', 'password' => 'wrong'])
            ->assertOk()
            ->assertSee('value="20240001"', false)
            ->assertDontSee('value="wrong"', false)
            ->getContent();

        $this->assertGuest();
        $this->assertSame(1, substr_count($html, 'Invalid credentials.'));

        $passwordInputAt = strpos($html, 'id="password"');
        $messageAt = strpos($html, 'Invalid credentials.');
        $this->assertGreaterThan($passwordInputAt, $messageAt, 'The message comes after the password field.');
        $this->assertMatchesRegularExpression('/<p id="password-error" data-error-for="login password"/', $html);

        // Both inputs are invalid and point at the one message.
        $this->assertMatchesRegularExpression('/id="login"[^>]*aria-invalid="true" aria-describedby="password-error"/', $html);
        $this->assertMatchesRegularExpression('/id="password"[^>]*aria-invalid="true" aria-describedby="password-error"/', $html);
        $this->assertStringContainsString('data-clear-errors', $html);
    }

    public function test_empty_fields_keep_their_own_messages(): void
    {
        $html = $this->from(route('login'))->followingRedirects()
            ->post(route('login.store'), ['login' => '', 'password' => ''])
            ->assertOk()
            ->assertSeeInOrder(['id="login"', 'Enter your ID number or email.', 'id="password"', 'Enter your password.'], false)
            ->assertDontSee('Invalid credentials.')
            ->getContent();

        $this->assertMatchesRegularExpression('/id="login"[^>]*aria-describedby="login-error"/', $html);
        $this->assertMatchesRegularExpression('/<p id="password-error" data-error-for="password"/', $html);
    }

    public function test_the_login_placeholder_shows_an_eight_digit_student_id(): void
    {
        $this->get(route('login'))->assertSee('placeholder="e.g. 20240001 or example@gmail.com"', false);
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

        // Back on the page, the wait counts down live from the flashed seconds.
        $page = $this->from(route('login'))->followingRedirects()
            ->post(route('login.store'), ['login' => '20240001', 'password' => 'wrong'])
            ->assertSee('Too many sign-in attempts.');
        $this->assertMatchesRegularExpression('/data-retry-countdown="\d+">Please try again in \d+ seconds\.</', $page->getContent());
    }
}
