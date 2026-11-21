<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\SettingsController;
use App\Mail\EmailCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Settings → My account: the signed-in admin changes their own email and password.
 */
class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'current-pass1';

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@tcc.edu.ph', 'password' => self::CURRENT_PASSWORD]);
    }

    /**
     * @return array<string, string>
     */
    private function newPassword(string $password = 'brand-new-pass2'): array
    {
        return ['current_password' => self::CURRENT_PASSWORD, 'password' => $password, 'password_confirmation' => $password];
    }

    public function test_an_admin_changes_their_email_after_entering_the_code_sent_to_it(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.account.email'), ['email' => ' New.Admin@TCC.edu.ph '])
            ->assertRedirect(SettingsController::tabUrl('my-account'))
            ->assertSessionHas('section', 'account')
            ->assertSessionHas('status', 'We sent a code to new.admin@tcc.edu.ph. Enter it below to finish changing your email.');
        $this->assertSame('admin@tcc.edu.ph', $admin->fresh()->email);

        $code = Mail::queued(EmailCodeMail::class)->sole()->code;

        $this->actingAs($admin)
            ->put(route('admin.settings.account.email.confirm'), ['code' => $code])
            ->assertRedirect(SettingsController::tabUrl('my-account'))
            ->assertSessionHas('status', 'Email changed. Sign in with new.admin@tcc.edu.ph from now on.');

        $this->assertSame('new.admin@tcc.edu.ph', $admin->fresh()->email);
    }

    public function test_an_unverified_admin_verifies_their_current_email_from_settings(): void
    {
        Mail::fake();
        $admin = User::factory()->unverified()->create(['role' => UserRole::Admin, 'email' => 'admin@tcc.edu.ph']);

        $this->actingAs($admin)->get(SettingsController::tabUrl('my-account'))
            ->assertSee('Not verified')
            ->assertSee('Verify email');

        $this->actingAs($admin)
            ->put(route('admin.settings.account.email'), ['email' => 'admin@tcc.edu.ph'])
            ->assertSessionHas('status', 'We sent a code to admin@tcc.edu.ph. Enter it below to verify your email.');
        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('admin@tcc.edu.ph'));

        $this->actingAs($admin)->get(SettingsController::tabUrl('my-account'))
            ->assertDontSee('Until then you still sign in with');

        $this->actingAs($admin)
            ->put(route('admin.settings.account.email.confirm'), ['code' => Mail::queued(EmailCodeMail::class)->sole()->code])
            ->assertSessionHas('status', 'Email verified.');

        $this->assertTrue($admin->fresh()->hasVerifiedEmail());
        $this->assertSame('admin@tcc.edu.ph', $admin->fresh()->email);
    }

    public function test_the_email_cannot_belong_to_another_account(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'taken@tcc.edu.ph']);

        $this->actingAs($admin)
            ->put(route('admin.settings.account.email'), ['email' => 'taken@tcc.edu.ph'])
            ->assertSessionHasErrors(['email' => 'Another account already uses this email.'], errorBag: 'accountEmail');

        $this->assertSame('admin@tcc.edu.ph', $admin->fresh()->email);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.account.password'), [...$this->newPassword(), 'current_password' => 'wrong-pass1'])
            ->assertRedirect(SettingsController::tabUrl('my-account'))
            ->assertSessionHasErrors(['current_password' => 'That is not your current password.'], errorBag: 'accountPassword');

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $admin->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_strong_enough(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.account.password'), [...$this->newPassword(), 'password_confirmation' => 'something-else3'])
            ->assertSessionHasErrors(['password' => 'The two new passwords don’t match.'], errorBag: 'accountPassword');

        $this->actingAs($admin)
            ->put(route('admin.settings.account.password'), $this->newPassword('onlyletters'))
            ->assertSessionHasErrors(['password' => 'The password field must contain at least one number.'], errorBag: 'accountPassword');

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $admin->fresh()->password));
    }

    public function test_changing_the_password_ends_the_admins_other_sessions_only(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $otherUser = User::factory()->create();
        $admin->createToken('Pixel 8');
        $otherUser->createToken('iPhone');
        $rememberToken = $admin->getRememberToken();
        foreach (['admin-laptop' => $admin, 'admin-phone' => $admin, 'student-phone' => $otherUser] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }

        $this->actingAs($admin)
            ->put(route('admin.settings.account.password'), $this->newPassword())
            ->assertRedirect(SettingsController::tabUrl('my-account'))
            ->assertSessionHas('status', 'Password changed. You were signed out everywhere else, including the mobile app.');

        $admin->refresh();
        $this->assertTrue(Hash::check('brand-new-pass2', $admin->password));
        $this->assertNotSame($rememberToken, $admin->getRememberToken());
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(1, $otherUser->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'admin-laptop']);
        $this->assertDatabaseMissing('sessions', ['id' => 'admin-phone']);
        $this->assertDatabaseHas('sessions', ['id' => 'student-phone']);
        $this->assertAuthenticatedAs($admin);
    }
}
