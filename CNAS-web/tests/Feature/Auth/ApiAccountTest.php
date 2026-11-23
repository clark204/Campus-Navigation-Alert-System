<?php

namespace Tests\Feature\Auth;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The mobile app's Change password and Change email (/api/account), with the rules of Settings → My account on the
 * web (MyAccountTest, EmailChangeTest). Requests carry a real token, so "this phone stays signed in" is tested.
 */
class ApiAccountTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'current-pass1';

    private User $student;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();
        $this->student = User::factory()->create(['role' => UserRole::Student, 'email' => 'old@tcc.edu.ph', 'password' => self::CURRENT_PASSWORD]);
        $this->token = $this->student->createToken('This phone')->plainTextToken;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function send(string $method, string $route, array $data = []): TestResponse
    {
        return $this->withToken($this->token)->json($method, route($route), $data);
    }

    /**
     * @return array<string, string>
     */
    private function newPassword(string $password = 'brand-new-pass2'): array
    {
        return ['current_password' => self::CURRENT_PASSWORD, 'password' => $password, 'password_confirmation' => $password];
    }

    private function codeSentTo(string $email): string
    {
        return Mail::queued(EmailCodeMail::class)->filter(fn (EmailCodeMail $mail) => $mail->hasTo($email))->last()->code;
    }

    public function test_it_needs_a_token(): void
    {
        $this->putJson(route('api.account.password'), $this->newPassword())->assertUnauthorized();
        $this->putJson(route('api.account.email.update'), ['email' => 'new@tcc.edu.ph'])->assertUnauthorized();
    }

    public function test_changing_the_password_signs_out_everywhere_else_but_keeps_this_phone(): void
    {
        config(['session.driver' => 'database']);
        $this->student->createToken('Old tablet');
        $otherUser = User::factory()->create();
        $otherUser->createToken('iPhone');
        $rememberToken = $this->student->getRememberToken();
        foreach (['student-laptop' => $this->student, 'someone-else' => $otherUser] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }

        $this->send('PUT', 'api.account.password', $this->newPassword())
            ->assertOk()
            ->assertJsonPath('message', 'Password changed. You were signed out everywhere else, including the website.');

        $this->student->refresh();
        $this->assertTrue(Hash::check('brand-new-pass2', $this->student->password));
        $this->assertNotSame($rememberToken, $this->student->getRememberToken());
        $this->assertSame(['This phone'], $this->student->tokens()->pluck('name')->all());
        $this->assertSame(1, $otherUser->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'student-laptop']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson(route('api.me'))->assertOk();
    }

    public function test_a_temporary_password_is_replaced_from_the_app(): void
    {
        $this->student->forceFill(['must_change_password' => true])->save();

        $this->send('PUT', 'api.account.password', $this->newPassword())->assertOk()->assertJsonPath('user.must_change_password', false);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $this->send('PUT', 'api.account.password', [...$this->newPassword(), 'current_password' => 'wrong-pass1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'That is not your current password.']);

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $this->student->fresh()->password));
        $this->assertSame(1, $this->student->tokens()->count());
    }

    public function test_the_new_password_follows_the_web_rules(): void
    {
        $this->send('PUT', 'api.account.password', [...$this->newPassword(), 'password_confirmation' => 'something-else3'])
            ->assertJsonValidationErrors(['password' => 'The two new passwords don’t match.']);
        $this->send('PUT', 'api.account.password', $this->newPassword('onlyletters'))
            ->assertJsonValidationErrors(['password' => 'The password field must contain at least one number.']);
        $this->send('PUT', 'api.account.password', $this->newPassword(self::CURRENT_PASSWORD))
            ->assertJsonValidationErrors(['password' => 'Choose a password different from your current one.']);
    }

    public function test_the_email_changes_only_after_the_code_sent_to_the_new_address(): void
    {
        $this->send('PUT', 'api.account.email.update', ['email' => ' New@TCC.edu.ph '])
            ->assertAccepted()
            ->assertJsonPath('message', 'We sent a code to new@tcc.edu.ph. Enter it below to finish changing your email.')
            ->assertJsonPath('email', 'old@tcc.edu.ph')
            ->assertJsonPath('pending', ['email' => 'new@tcc.edu.ph', 'resend_in' => 60]);

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('new@tcc.edu.ph') && $mail->purpose === EmailCodePurpose::ChangeEmail);
        $this->assertSame('old@tcc.edu.ph', $this->student->fresh()->email);

        $this->send('GET', 'api.account.email')->assertOk()->assertJsonPath('pending.email', 'new@tcc.edu.ph');

        $this->send('PUT', 'api.account.email.confirm', ['code' => $this->codeSentTo('new@tcc.edu.ph')])
            ->assertOk()
            ->assertJsonPath('message', 'Email changed. Sign in with new@tcc.edu.ph from now on.')
            ->assertJsonPath('user.email', 'new@tcc.edu.ph')
            ->assertJsonPath('user.email_verified', true);

        $this->send('GET', 'api.account.email')->assertJsonPath('pending', null);
    }

    public function test_a_wrong_code_keeps_the_old_email(): void
    {
        $this->send('PUT', 'api.account.email.update', ['email' => 'new@tcc.edu.ph']);
        $wrong = $this->codeSentTo('new@tcc.edu.ph') === '000000' ? '111111' : '000000';

        $this->send('PUT', 'api.account.email.confirm', ['code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'That code is not right. You have 4 tries left.']);
        $this->assertSame('old@tcc.edu.ph', $this->student->fresh()->email);
    }

    public function test_an_email_another_account_uses_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@tcc.edu.ph']);

        $this->send('PUT', 'api.account.email.update', ['email' => 'taken@tcc.edu.ph'])
            ->assertJsonValidationErrors(['email' => 'Another account already uses this email.']);
        Mail::assertNothingQueued();
    }

    public function test_resend_waits_and_then_sends_a_new_code_to_the_new_address(): void
    {
        $this->send('POST', 'api.account.email.resend')
            ->assertJsonValidationErrors(['email' => 'Enter your new email again to get a code.']);

        $this->send('PUT', 'api.account.email.update', ['email' => 'new@tcc.edu.ph']);
        $this->send('POST', 'api.account.email.resend')
            ->assertJsonValidationErrors(['code' => 'Please wait 60 seconds before asking for a new code.']);

        $this->travel(61)->seconds();
        $this->send('POST', 'api.account.email.resend')
            ->assertAccepted()
            ->assertJsonPath('message', 'We sent a new code to new@tcc.edu.ph.');
        Mail::assertQueuedCount(2);
    }

    public function test_cancelling_keeps_the_old_email(): void
    {
        $this->send('PUT', 'api.account.email.update', ['email' => 'new@tcc.edu.ph']);

        $this->send('DELETE', 'api.account.email.cancel')
            ->assertOk()
            ->assertJsonPath('message', 'Email change cancelled. You still sign in with old@tcc.edu.ph.')
            ->assertJsonPath('pending', null);
    }

    public function test_sending_codes_shares_the_web_settings_limit(): void
    {
        for ($sent = 0; $sent < 10; $sent++) {
            $this->travel(61)->seconds();
            $this->send('PUT', 'api.account.email.update', ['email' => "new{$sent}@tcc.edu.ph"])->assertAccepted();
        }

        $this->travel(61)->seconds();
        $this->send('PUT', 'api.account.email.update', ['email' => 'one-more@tcc.edu.ph'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Too many codes asked for.'));
    }
}
