<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\User;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * Password reset for the mobile app (Api\PasswordRecoveryController): /api/password/forgot, /verify-code and /reset.
 */
class ApiPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->student = User::factory()->unverified()->create([
            'role' => UserRole::Student, 'school_id' => CorFixture::STUDENT_ID, 'first_name' => 'Juan',
            'email' => 'juan@tcc.edu.ph', 'password' => 'old-pass1',
        ]);
    }

    public function test_forgot_answers_the_same_for_known_and_unknown_accounts(): void
    {
        $expected = ['message' => PasswordRecovery::SENT_MESSAGE, 'resend_in' => 60];

        $this->postJson(route('api.password.forgot'), ['login' => CorFixture::STUDENT_ID])->assertStatus(202)->assertExactJson($expected);
        $this->postJson(route('api.password.forgot'), ['login' => 'nobody@example.com'])->assertStatus(202)->assertExactJson($expected);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph'));
    }

    public function test_reset_with_the_code_sets_the_password_and_ends_app_tokens(): void
    {
        $this->student->createToken('Old phone');
        $this->postJson(route('api.password.forgot'), ['login' => 'juan@tcc.edu.ph']);
        $code = Mail::queued(EmailCodeMail::class)->sole()->code;

        $resetToken = $this->postJson(route('api.password.verify-code'), ['login' => 'juan@tcc.edu.ph', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('expires_in', PasswordRecovery::GRANT_TTL_MINUTES * 60)
            ->json('reset_token');
        // The code alone changes nothing.
        $this->assertTrue(Hash::check('old-pass1', $this->student->fresh()->password));

        $this->postJson(route('api.password.reset'), [
            'reset_token' => $resetToken, 'password' => 'brand-new2', 'password_confirmation' => 'brand-new2',
        ])->assertOk()->assertJsonPath('message', 'Password changed. Sign in with your new password.');

        $student = $this->student->fresh();
        $this->assertTrue(Hash::check('brand-new2', $student->password));
        $this->assertTrue($student->hasVerifiedEmail());
        $this->assertSame(0, $student->tokens()->count());

        // The grant works once.
        $this->postJson(route('api.password.reset'), [
            'reset_token' => $resetToken, 'password' => 'another-9', 'password_confirmation' => 'another-9',
        ])->assertUnprocessable()->assertJsonPath('errors.reset_token.0', PasswordRecovery::GRANT_EXPIRED_MESSAGE);
    }

    public function test_a_wrong_code_is_a_422_on_code(): void
    {
        $this->postJson(route('api.password.forgot'), ['login' => 'juan@tcc.edu.ph']);

        $this->postJson(route('api.password.verify-code'), ['login' => 'juan@tcc.edu.ph', 'code' => '12345x'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson(route('api.password.verify-code'), ['login' => 'nobody@example.com', 'code' => '123456'])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', PasswordRecovery::RESET_CODE_MESSAGE);
    }

    public function test_reset_without_a_valid_grant_is_a_422(): void
    {
        $this->postJson(route('api.password.reset'), ['reset_token' => 'made-up', 'password' => 'brand-new2', 'password_confirmation' => 'brand-new2'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reset_token.0', PasswordRecovery::GRANT_EXPIRED_MESSAGE);
    }
}
