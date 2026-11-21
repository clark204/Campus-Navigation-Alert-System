<?php

namespace Tests\Feature\Auth;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\User;
use App\Services\EmailCodes;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Forgot password?" on the website (Auth\PasswordResetController, PasswordRecovery): a code to the account's email,
 * then the code and a new password. The API is ApiPasswordRecoveryTest.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();
        $this->student = User::factory()->create([
            'role' => UserRole::Student, 'school_id' => '20240001', 'email' => 'juan@tcc.edu.ph', 'password' => 'old-pass1',
        ]);
    }

    private function forgot(string $login): TestResponse
    {
        return $this->post(route('password.email'), ['login' => $login]);
    }

    private function enterCode(string $code): TestResponse
    {
        return $this->from(route('password.reset'))->post(route('password.verify-code'), ['code' => $code]);
    }

    private function choosePassword(string $password = 'brand-new2'): TestResponse
    {
        return $this->from(route('password.reset'))->post(route('password.update'), [
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    /** Both steps: the code, then (if it was right) the new password. */
    private function reset(string $code, string $password = 'brand-new2'): TestResponse
    {
        $response = $this->enterCode($code);

        return session()->has(PasswordRecovery::SESSION_GRANT) ? $this->choosePassword($password) : $response;
    }

    private function sentCode(): string
    {
        return Mail::queued(EmailCodeMail::class)->last()->code;
    }

    public function test_the_sign_in_page_links_to_forgot_password(): void
    {
        $this->get(route('login'))->assertSee('href="'.route('password.request').'"', false);
        $this->get(route('password.request'))->assertOk()->assertSee(route('password.no-email'));
    }

    public function test_without_email_access_the_student_is_sent_to_the_administrator(): void
    {
        // No self-service recovery: a COR can be copied, so it can't prove who someone is.
        $this->get(route('password.no-email'))
            ->assertOk()
            ->assertSee('Go to the CNAS administrator')
            ->assertSee('Bring your school ID')
            ->assertSee(route('password.no-email', ['topic' => 'account']).'#feedback', false);

        // The message link opens the footer form with the Account topic picked; the admin gets it in the bell.
        $this->get(route('password.no-email', ['topic' => 'account']))->assertSee('<option value="account" selected>', false);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->from(route('password.no-email', ['topic' => 'account']))
            ->post(route('concerns.store'), ['email' => 'juan.new@gmail.com', 'topic' => 'account', 'message' => 'I can’t open my old email. Student 20240001.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $admin->unreadNotifications()->count());
    }

    public function test_a_code_goes_to_the_account_email_when_asked_by_email(): void
    {
        $this->forgot(' Juan@TCC.edu.ph ')
            ->assertRedirect(route('password.reset'))
            ->assertSessionHas('status', PasswordRecovery::SENT_MESSAGE);

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph') && $mail->purpose === EmailCodePurpose::ResetPassword);
    }

    public function test_a_code_goes_to_the_account_email_when_asked_by_student_id(): void
    {
        $this->forgot('20240001')->assertRedirect(route('password.reset'));

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph'));
    }

    public function test_an_unknown_account_gets_exactly_the_same_answer_and_no_email(): void
    {
        $known = $this->forgot('20240001');
        $this->flushSession();
        $unknown = $this->forgot('20249999');

        $unknown->assertRedirect(route('password.reset'))->assertSessionHas('status', PasswordRecovery::SENT_MESSAGE);
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        Mail::assertQueuedCount(1);

        // The reset page doesn't tell either, and a code for it fails like a wrong code.
        $this->get(route('password.reset'))->assertOk()->assertSee('If an account matches');
        $this->reset('123456')->assertSessionHasErrors(['code' => PasswordRecovery::RESET_CODE_MESSAGE]);
    }

    public function test_the_code_resets_the_password_and_verifies_the_email(): void
    {
        $student = User::factory()->unverified()->create(['role' => UserRole::Student, 'school_id' => '20240002', 'password' => 'old-pass1']);
        $this->forgot('20240002');

        $this->reset($this->sentCode())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Password changed. Sign in with your new password.');

        $student->refresh();
        $this->assertTrue(Hash::check('brand-new2', $student->password));
        $this->assertTrue($student->hasVerifiedEmail());
        $this->assertNull(session(PasswordRecovery::SESSION_LOGIN));

        $this->post(route('login.store'), ['login' => '20240002', 'password' => 'brand-new2'])
            ->assertRedirect(route($student->role->homeRoute()));
    }

    public function test_a_reset_ends_every_session_and_app_token(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $this->student->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->student->createToken('Phone');
        $rememberToken = $this->student->getRememberToken();

        $this->forgot('20240001');
        $this->reset($this->sentCode());

        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
        $this->assertSame(0, $this->student->tokens()->count());
        $this->assertNotSame($rememberToken, $this->student->fresh()->getRememberToken());
    }

    public function test_a_wrong_code_changes_nothing_and_counts_as_a_try(): void
    {
        $this->forgot('20240001');
        $code = $this->sentCode();

        $this->reset($code === '000000' ? '111111' : '000000')
            ->assertSessionHasErrors(['code' => PasswordRecovery::RESET_CODE_MESSAGE]);

        $this->assertTrue(Hash::check('old-pass1', $this->student->fresh()->password));
        $this->assertSame(1, app(EmailCodes::class)->current($this->student, EmailCodePurpose::ResetPassword)->attempts);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->forgot('20240001');
        $code = $this->sentCode();
        $this->travel(EmailCodes::TTL_MINUTES + 1)->minutes();

        $this->reset($code)->assertSessionHasErrors(['code' => PasswordRecovery::RESET_CODE_MESSAGE]);

        $this->assertTrue(Hash::check('old-pass1', $this->student->fresh()->password));
    }

    public function test_the_code_is_checked_before_the_password_form_appears(): void
    {
        $this->forgot('20240001');
        $this->get(route('password.reset'))->assertSee('Enter your code')->assertDontSee('name="password"', false);

        $this->enterCode($this->sentCode())
            ->assertRedirect(route('password.reset'))
            ->assertSessionHas('status', 'Code confirmed. Now choose your new password.');

        $this->get(route('password.reset'))->assertSee('Choose a new password')->assertSee('name="password"', false)->assertDontSee('name="code"', false);
        // Nothing changed yet.
        $this->assertTrue(Hash::check('old-pass1', $this->student->fresh()->password));
    }

    public function test_the_password_step_needs_a_confirmed_code(): void
    {
        $this->forgot('20240001');

        $this->choosePassword()->assertSessionHasErrors(['reset_token' => PasswordRecovery::GRANT_EXPIRED_MESSAGE]);

        $this->assertTrue(Hash::check('old-pass1', $this->student->fresh()->password));
    }

    public function test_a_confirmed_code_expires_if_the_password_waits_too_long(): void
    {
        $this->forgot('20240001');
        $this->enterCode($this->sentCode());
        $this->travel(PasswordRecovery::GRANT_TTL_MINUTES + 1)->minutes();

        $this->choosePassword()->assertSessionHasErrors(['reset_token' => PasswordRecovery::GRANT_EXPIRED_MESSAGE]);
        $this->get(route('password.reset'))->assertSee('Enter your code');
    }

    public function test_the_new_password_follows_the_password_rules_and_the_code_stays_confirmed(): void
    {
        $this->forgot('20240001');
        $this->enterCode($this->sentCode());

        $this->choosePassword('short')->assertSessionHasErrors('password');
        $this->choosePassword('brand-new2')->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('brand-new2', $this->student->fresh()->password));
    }

    public function test_resending_waits_a_minute_without_saying_whether_the_account_exists(): void
    {
        $this->forgot('20240001');
        $this->post(route('password.resend'))->assertRedirect(route('password.reset'))->assertSessionHas('status', PasswordRecovery::SENT_MESSAGE);
        Mail::assertQueuedCount(1);

        $this->travel(EmailCodes::RESEND_SECONDS)->seconds();
        $this->post(route('password.resend'));
        Mail::assertQueuedCount(2);
    }

    public function test_the_reset_page_needs_a_login_first(): void
    {
        $this->get(route('password.reset'))->assertRedirect(route('password.request'));
    }

    public function test_asking_for_codes_is_rate_limited_per_login(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->forgot('20240001');
        }

        $this->forgot('20240001')->assertSessionHasErrors('login');
    }
}
