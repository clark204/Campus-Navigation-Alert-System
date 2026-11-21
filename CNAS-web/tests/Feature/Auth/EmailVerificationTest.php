<?php

namespace Tests\Feature\Auth;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\Alert;
use App\Models\EmailCode;
use App\Models\SafetyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Verify your email" (web): the 6-digit code (App\Services\EmailCodes), what an unverified user can and can't open,
 * and the admin's exemption. Registration sending the code is in RegistrationTest; the API is in
 * ApiEmailVerificationTest.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();
    }

    private function unverifiedStudent(): User
    {
        return User::factory()->unverified()->create(['role' => UserRole::Student, 'school_id' => '20240001', 'email' => 'juan@tcc.edu.ph']);
    }

    /**
     * The code in the newest email to this address.
     */
    private function latestCode(string $email = 'juan@tcc.edu.ph'): string
    {
        return Mail::queued(EmailCodeMail::class)->filter(fn (EmailCodeMail $mail) => $mail->hasTo($email))->last()->code;
    }

    private function sendCode(User $user): string
    {
        $this->actingAs($user)->post(route('email.verify.resend'))->assertSessionHasNoErrors();

        return $this->latestCode($user->email);
    }

    private function enter(User $user, string $code): TestResponse
    {
        return $this->actingAs($user)->from(route('email.verify'))->post(route('email.verify.store'), ['code' => $code]);
    }

    private function wrongCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_the_code_is_emailed_and_stored_only_as_a_hash(): void
    {
        $student = $this->unverifiedStudent();
        $code = $this->sendCode($student);

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph')
            && $mail->purpose === EmailCodePurpose::VerifyEmail
            && preg_match('/^\d{6}$/', $mail->code) === 1);

        $row = EmailCode::sole();
        $this->assertSame('juan@tcc.edu.ph', $row->email);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertEquals(now()->addMinutes(10), $row->expires_at);
    }

    public function test_the_verify_page_shows_where_the_code_went(): void
    {
        $student = $this->unverifiedStudent();
        $this->sendCode($student);

        $this->actingAs($student)->get(route('email.verify'))
            ->assertOk()
            ->assertSee('Verify your email')
            ->assertSee('juan@tcc.edu.ph')
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('data-resend-cooldown="60"', false);
    }

    public function test_the_right_code_verifies_the_email_and_opens_the_dashboard(): void
    {
        $student = $this->unverifiedStudent();
        $code = $this->sendCode($student);

        $this->enter($student, substr($code, 0, 3).' '.substr($code, 3))
            ->assertRedirect(route('student.dashboard'));

        $this->assertEquals(now(), $student->fresh()->email_verified_at);
        $this->assertSame(0, EmailCode::count());

        // A toast on the dashboard, once.
        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('role="region" aria-label="Notifications"', false)
            ->assertSee('popover="manual" class="toast"', false)
            ->assertSeeInOrder(['Email verified', "Welcome to TCC NAV, {$student->first_name}!"]);
        $this->actingAs($student)->get(route('student.dashboard'))->assertDontSee('Email verified');
    }

    public function test_settings_show_the_email_as_verified(): void
    {
        $student = $this->unverifiedStudent();
        $this->enter($student, $this->sendCode($student));

        $this->actingAs($student)->get(route('student.settings', ['tab' => 'my-account']))
            ->assertOk()
            ->assertSeeInOrder(['Email', 'Verified', 'Sign-in email']);
    }

    public function test_a_wrong_code_counts_as_a_try(): void
    {
        $student = $this->unverifiedStudent();
        $code = $this->sendCode($student);

        $this->enter($student, $this->wrongCode($code))
            ->assertRedirect(route('email.verify'))
            ->assertSessionHasErrors(['code' => 'That code is not right. You have 4 tries left.']);

        $this->assertNull($student->fresh()->email_verified_at);
        $this->assertSame(1, EmailCode::sole()->attempts);
    }

    public function test_a_code_must_be_six_digits(): void
    {
        $student = $this->unverifiedStudent();
        $this->sendCode($student);

        $this->enter($student, '12345')->assertSessionHasErrors(['code' => 'The code is 6 digits, like 123456.']);
        $this->assertSame(0, EmailCode::sole()->attempts);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $student = $this->unverifiedStudent();
        $code = $this->sendCode($student);

        $this->travel(10)->minutes();

        $this->enter($student, $code)->assertSessionHasErrors(['code' => 'This code has expired. Send a new one.']);
        $this->assertNull($student->fresh()->email_verified_at);
    }

    public function test_after_five_wrong_tries_even_the_right_code_needs_a_new_one(): void
    {
        $student = $this->unverifiedStudent();
        $code = $this->sendCode($student);

        foreach (range(1, 4) as $try) {
            $this->enter($student, $this->wrongCode($code));
        }
        $this->enter($student, $this->wrongCode($code))->assertSessionHasErrors(['code' => 'Too many wrong tries. Send a new code.']);
        $this->enter($student, $code)->assertSessionHasErrors(['code' => 'Too many wrong tries. Send a new code.']);
        $this->assertNull($student->fresh()->email_verified_at);

        // A new code works again.
        $this->travel(61)->seconds();
        $this->enter($student, $this->sendCode($student))->assertRedirect(route('student.dashboard'));
        $this->assertNotNull($student->fresh()->email_verified_at);
    }

    public function test_a_new_code_waits_sixty_seconds_and_replaces_the_old_one(): void
    {
        $student = $this->unverifiedStudent();
        $oldCode = $this->sendCode($student);

        $this->travel(45)->seconds();
        $this->actingAs($student)->post(route('email.verify.resend'))
            ->assertSessionHasErrors(['code' => 'Please wait 15 seconds before asking for a new code.']);
        Mail::assertQueuedCount(1);

        $this->travel(15)->seconds();
        $this->actingAs($student)->post(route('email.verify.resend'))
            ->assertRedirect(route('email.verify'))
            ->assertSessionHas('status', 'We sent a new code to juan@tcc.edu.ph.');
        $newCode = $this->latestCode();
        $this->assertSame(1, EmailCode::count());

        if ($newCode !== $oldCode) {
            $this->enter($student, $oldCode)->assertSessionHasErrors('code');
        }
        $this->enter($student, $newCode)->assertRedirect(route('student.dashboard'));
    }

    public function test_an_unverified_user_is_sent_to_the_verify_page_from_portal_pages(): void
    {
        $student = $this->unverifiedStudent();
        $staff = User::factory()->unverified()->create(['role' => UserRole::Staff]);

        foreach (['student.dashboard', 'student.settings', 'student.schedule.index', 'student.saved', 'student.help'] as $route) {
            $this->actingAs($student)->get(route($route))->assertRedirect(route('email.verify'));
        }
        $this->actingAs($staff)->get(route('faculty.dashboard'))->assertRedirect(route('email.verify'));
        $this->actingAs($student)->get(route('login'))->assertRedirect(route('email.verify'));
    }

    public function test_an_unverified_user_can_still_use_the_map_alerts_i_m_safe_and_sign_out(): void
    {
        $student = $this->unverifiedStudent();
        $alert = Alert::factory()->create();

        $this->actingAs($student)->get(route('navigate'))->assertOk();
        $this->actingAs($student)->getJson(route('api.alerts.active'))->assertOk();
        $this->actingAs($student)->postJson(route('push-subscriptions.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'BPublicKeyFromTheBrowser', 'auth' => 'authSecret'],
            'content_encoding' => 'aes128gcm',
        ])->assertOk();
        $this->actingAs($student)->postJson(route('alerts.safe', $alert))->assertOk();
        $this->assertTrue(SafetyCheck::sole()->user->is($student));

        $this->actingAs($student)->post(route('logout'))->assertRedirect(route('landing'));
        $this->assertGuest();
    }

    public function test_signing_in_unverified_sends_a_code_once_and_opens_the_verify_page(): void
    {
        // An account the admin created, or one from before email verification.
        $this->unverifiedStudent();

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'password'])
            ->assertRedirect(route('email.verify'));
        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph'));

        // Signing in again while that code still works doesn't send another email.
        $this->post(route('logout'));
        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'password'])
            ->assertRedirect(route('email.verify'));
        Mail::assertQueuedCount(1);
    }

    public function test_the_admin_never_has_to_verify(): void
    {
        $admin = User::factory()->unverified()->create(['role' => UserRole::Admin, 'school_id' => 'admin']);

        $this->post(route('login.store'), ['login' => 'admin', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
        $this->actingAs($admin)->get(route('email.verify'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)->post(route('email.verify.resend'));

        Mail::assertNothingQueued();
    }

    public function test_a_verified_user_skips_the_verify_page(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->actingAs($student)->get(route('email.verify'))->assertRedirect(route('student.dashboard'));
        $this->actingAs($student)->get(route('student.dashboard'))->assertOk();
    }

    public function test_a_mistyped_email_can_be_fixed_on_the_verify_page(): void
    {
        $student = $this->unverifiedStudent();
        $this->sendCode($student);
        User::factory()->create(['email' => 'taken@tcc.edu.ph']);

        $this->actingAs($student)->from(route('email.verify'))->put(route('email.verify.email'), ['email' => 'taken@tcc.edu.ph'])
            ->assertRedirect(route('email.verify'))
            ->assertSessionHasErrors(['email' => 'Another account already uses this email.']);

        $this->actingAs($student)->put(route('email.verify.email'), ['email' => ' Juan.Real@TCC.edu.ph '])
            ->assertRedirect(route('email.verify'))
            ->assertSessionHas('status', 'Email changed. We sent a new code to juan.real@tcc.edu.ph.');

        $this->assertSame('juan.real@tcc.edu.ph', $student->fresh()->email);
        $this->enter($student->fresh(), $this->latestCode('juan.real@tcc.edu.ph'))->assertRedirect(route('student.dashboard'));
        $this->assertNotNull($student->fresh()->email_verified_at);
    }

    public function test_checking_codes_is_rate_limited_per_user(): void
    {
        $student = $this->unverifiedStudent();
        $code = $this->sendCode($student);

        foreach (range(1, 10) as $try) {
            $this->enter($student, '12345');
        }

        $this->enter($student, $code)->assertSessionHasErrors(['code' => 'Too many tries. Please try again in 60 seconds.']);
        $this->assertNull($student->fresh()->email_verified_at);
    }

    public function test_expired_codes_are_pruned(): void
    {
        $this->sendCode($this->unverifiedStudent());
        $this->travel(11)->minutes();
        $this->sendCode(User::factory()->unverified()->create(['email' => 'other@tcc.edu.ph']));

        $this->artisan('model:prune', ['--model' => [EmailCode::class]])->assertSuccessful();

        $this->assertSame('other@tcc.edu.ph', EmailCode::sole()->email);
    }
}
