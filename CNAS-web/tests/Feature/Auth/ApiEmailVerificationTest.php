<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Verify your email" for the mobile app: POST /api/email/verify and /api/email/resend, and email_verified in the
 * user JSON. Same codes as the web (EmailVerificationTest).
 */
class ApiEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();
        $this->student = User::factory()->unverified()->create(['role' => UserRole::Student, 'school_id' => '20240001', 'email' => 'juan@tcc.edu.ph']);
    }

    private function latestCode(): string
    {
        return Mail::queued(EmailCodeMail::class)->last()->code;
    }

    public function test_signing_in_unverified_sends_a_code_and_says_the_email_is_not_verified(): void
    {
        $this->postJson(route('api.login'), ['login' => '20240001', 'password' => 'password', 'device_name' => 'Pixel'])
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph'));
    }

    public function test_the_right_code_verifies_the_email(): void
    {
        Sanctum::actingAs($this->student);
        $this->postJson(route('api.email.resend'))->assertAccepted();

        $this->postJson(route('api.email.verify'), ['code' => $this->latestCode()])
            ->assertOk()
            ->assertJsonPath('email', 'juan@tcc.edu.ph')
            ->assertJsonPath('email_verified', true);

        $this->assertNotNull($this->student->fresh()->email_verified_at);
        $this->getJson(route('api.me'))->assertJsonPath('email_verified', true);
    }

    public function test_a_wrong_code_is_a_validation_error(): void
    {
        Sanctum::actingAs($this->student);
        $this->postJson(route('api.email.resend'));
        $code = $this->latestCode();

        $this->postJson(route('api.email.verify'), ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code is not right. You have 4 tries left.']);

        $this->assertNull($this->student->fresh()->email_verified_at);
    }

    public function test_resend_says_how_long_to_wait(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson(route('api.email.resend'))
            ->assertAccepted()
            ->assertExactJson(['message' => 'We sent a new code to juan@tcc.edu.ph.', 'resend_in' => 60]);

        $this->travel(30)->seconds();
        $this->postJson(route('api.email.resend'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['Please wait 30 seconds before asking for a new code.']);
        Mail::assertQueuedCount(1);
    }

    public function test_the_admin_is_always_verified(): void
    {
        $admin = User::factory()->unverified()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->getJson(route('api.me'))->assertJsonPath('email_verified', true);
        $this->postJson(route('api.email.resend'))->assertOk()->assertJsonPath('message', 'Your email is already verified.');
        Mail::assertNothingQueued();
    }

    public function test_both_endpoints_need_a_token(): void
    {
        $this->postJson(route('api.email.verify'), ['code' => '123456'])->assertUnauthorized();
        $this->postJson(route('api.email.resend'))->assertUnauthorized();
    }

    public function test_resending_is_rate_limited_per_user(): void
    {
        Sanctum::actingAs($this->student);

        foreach (range(1, 10) as $request) {
            $this->postJson(route('api.email.resend'));
            $this->travel(61)->seconds();
        }

        $this->postJson(route('api.email.resend'))
            ->assertTooManyRequests()
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Too many codes asked for.'));
        Mail::assertQueuedCount(10);
    }
}
