<?php

namespace Tests\Feature\Portal;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Settings → Account → Email (shared AccountController): a new email only replaces the old one after the code sent to
 * it is entered, so a typo can't lock anyone out (docs/deploy-review.md #4). The admin side is in MyAccountTest.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();
        $this->student = User::factory()->create(['role' => UserRole::Student, 'email' => 'old@tcc.edu.ph']);
    }

    private function settingsUrl(): string
    {
        return route('student.settings', ['tab' => 'my-account']);
    }

    private function askFor(string $email): TestResponse
    {
        return $this->actingAs($this->student)->put(route('student.settings.account.email'), ['email' => $email]);
    }

    private function confirm(string $code): TestResponse
    {
        return $this->actingAs($this->student)->put(route('student.settings.account.email.confirm'), ['code' => $code]);
    }

    private function codeSentTo(string $email): string
    {
        return Mail::queued(EmailCodeMail::class)->filter(fn (EmailCodeMail $mail) => $mail->hasTo($email))->last()->code;
    }

    public function test_the_email_changes_only_after_the_code_sent_to_the_new_address(): void
    {
        $this->askFor('new@tcc.edu.ph')->assertRedirect($this->settingsUrl());

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('new@tcc.edu.ph')
            && ! $mail->hasTo('old@tcc.edu.ph')
            && $mail->purpose === EmailCodePurpose::ChangeEmail);
        $this->assertSame('old@tcc.edu.ph', $this->student->fresh()->email);

        // The old email still signs in until the code is entered.
        $this->post(route('logout'));
        $this->post(route('login.store'), ['login' => 'old@tcc.edu.ph', 'password' => 'password'])->assertRedirect(route('student.dashboard'));

        $this->confirm($this->codeSentTo('new@tcc.edu.ph'))
            ->assertRedirect($this->settingsUrl())
            ->assertSessionHas('status', 'Email changed. Sign in with new@tcc.edu.ph from now on.');

        $student = $this->student->fresh();
        $this->assertSame('new@tcc.edu.ph', $student->email);
        $this->assertEquals(now(), $student->email_verified_at);
        $this->assertSame(0, EmailCode::count());
    }

    public function test_a_wrong_code_keeps_the_old_email(): void
    {
        $this->askFor('new@tcc.edu.ph');
        $code = $this->codeSentTo('new@tcc.edu.ph');

        $this->confirm($code === '000000' ? '111111' : '000000')
            ->assertRedirect($this->settingsUrl())
            ->assertSessionHasErrors(['code' => 'That code is not right. You have 4 tries left.'], errorBag: 'accountEmail');

        $this->assertSame('old@tcc.edu.ph', $this->student->fresh()->email);
    }

    public function test_while_a_change_waits_the_account_tab_asks_for_the_code(): void
    {
        $this->askFor('new@tcc.edu.ph');

        $this->actingAs($this->student)->get($this->settingsUrl())
            ->assertOk()
            ->assertSeeInOrder(['Enter the 6-digit code we sent to', 'new@tcc.edu.ph', 'Until then you still sign in with old@tcc.edu.ph.'])
            ->assertSee('Confirm new email')
            ->assertSee('Cancel change')
            ->assertSee('data-resend-cooldown="60"', false);
    }

    public function test_a_change_can_be_cancelled(): void
    {
        $this->askFor('new@tcc.edu.ph');
        $code = $this->codeSentTo('new@tcc.edu.ph');

        $this->actingAs($this->student)->delete(route('student.settings.account.email.cancel'))
            ->assertRedirect($this->settingsUrl())
            ->assertSessionHas('status', 'Email change cancelled. You still sign in with old@tcc.edu.ph.');

        $this->confirm($code)->assertSessionHasErrors(['code' => 'There is no code to check. Send a new one.'], errorBag: 'accountEmail');
        $this->assertSame('old@tcc.edu.ph', $this->student->fresh()->email);
    }

    public function test_the_code_can_be_sent_again_to_the_new_address_after_the_cooldown(): void
    {
        $this->askFor('new@tcc.edu.ph');

        $this->actingAs($this->student)->post(route('student.settings.account.email.resend'))
            ->assertSessionHasErrors(['code' => 'Please wait 60 seconds before asking for a new code.'], errorBag: 'accountEmail');

        $this->travel(60)->seconds();
        $this->actingAs($this->student)->post(route('student.settings.account.email.resend'))
            ->assertSessionHas('status', 'We sent a new code to new@tcc.edu.ph.');

        Mail::assertQueuedCount(2);
        $this->confirm($this->codeSentTo('new@tcc.edu.ph'))->assertSessionHasNoErrors();
        $this->assertSame('new@tcc.edu.ph', $this->student->fresh()->email);
    }

    public function test_an_email_taken_after_the_code_was_sent_is_refused(): void
    {
        $this->askFor('new@tcc.edu.ph');
        User::factory()->create(['email' => 'new@tcc.edu.ph']);

        $this->confirm($this->codeSentTo('new@tcc.edu.ph'))
            ->assertSessionHasErrors(['email' => 'Another account already uses this email.'], errorBag: 'accountEmail');

        $this->assertSame('old@tcc.edu.ph', $this->student->fresh()->email);
    }

    public function test_a_verified_email_shows_no_send_button_until_a_new_address_is_typed(): void
    {
        $this->actingAs($this->student)->get($this->settingsUrl())
            ->assertSee('Verified')
            ->assertSee('data-email-change-submit hidden', escape: false)
            ->assertDontSee('Verify email');
    }

    public function test_entering_the_current_email_sends_nothing(): void
    {
        $this->askFor('Old@TCC.edu.ph')->assertSessionHas('status', 'old@tcc.edu.ph is already your sign-in email.');

        Mail::assertNothingQueued();
    }
}
