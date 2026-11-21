<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin > Accounts > "Set temporary password", and "Choose a new password" after signing in with one
 * (users.must_change_password, EnsurePasswordIsChanged, Auth\TemporaryPasswordController).
 */
class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $faculty;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->faculty = User::factory()->create(['role' => UserRole::Faculty, 'email' => 'maria@tcc.edu.ph', 'password' => 'forgotten1']);
    }

    private function setTemporary(User $account, string $password = 'temp-pass9'): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.accounts.show', $account))
            ->patch(route('admin.accounts.password', $account), ['password' => $password]);
    }

    private function signIn(string $password = 'temp-pass9'): TestResponse
    {
        return $this->post(route('login.store'), ['login' => 'maria@tcc.edu.ph', 'password' => $password]);
    }

    public function test_the_account_page_has_the_temporary_password_form(): void
    {
        $this->actingAs($this->admin)->get(route('admin.accounts.show', $this->faculty))
            ->assertSee('Set temporary password')
            ->assertSee('data-password-generate', false);
    }

    public function test_setting_one_forces_a_change_and_signs_them_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'their-browser', 'user_id' => $this->faculty->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->faculty->createToken('Phone');

        $this->setTemporary($this->faculty)
            ->assertRedirect(route('admin.accounts.show', $this->faculty))
            ->assertSessionHas('status');

        $faculty = $this->faculty->fresh();
        $this->assertTrue($faculty->must_change_password);
        $this->assertTrue(Hash::check('temp-pass9', $faculty->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'their-browser']);
        $this->assertSame(0, $faculty->tokens()->count());
        $this->actingAs($this->admin)->get(route('admin.accounts.show', $faculty))->assertSee('Waiting for them to change it');
    }

    public function test_the_temporary_password_follows_the_password_rules(): void
    {
        $this->setTemporary($this->faculty, 'short')->assertSessionHasErrors('password', errorBag: 'temporaryPassword');

        $this->assertFalse($this->faculty->fresh()->must_change_password);
    }

    public function test_admin_accounts_and_non_admins_cannot_use_it(): void
    {
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $this->setTemporary($otherAdmin)->assertForbidden();

        $this->actingAs($this->faculty)->patch(route('admin.accounts.password', $this->faculty), ['password' => 'temp-pass9'])->assertForbidden();
    }

    public function test_after_signing_in_with_it_the_portal_waits_for_a_new_password(): void
    {
        $this->setTemporary($this->faculty);
        $this->flushSession();
        auth()->logout();

        $this->signIn()->assertRedirect(route('password.change'));
        $this->get(route('faculty.dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('login'))->assertRedirect(route('password.change'));
        $this->getJson(route('faculty.dashboard'))->assertForbidden();

        // Still open meanwhile: the map and signing out.
        $this->get(route('navigate'))->assertOk();
        $this->get(route('password.change'))->assertOk()->assertSee('Choose a new password');
    }

    public function test_choosing_a_new_password_clears_the_flag(): void
    {
        $this->setTemporary($this->faculty);
        $this->flushSession();
        auth()->logout();
        $this->signIn();

        $this->put(route('password.change.update'), ['password' => 'my-own-pass3', 'password_confirmation' => 'my-own-pass3'])
            ->assertRedirect(route('faculty.dashboard'))
            ->assertSessionHas('toast');

        $faculty = $this->faculty->fresh();
        $this->assertFalse($faculty->must_change_password);
        $this->assertTrue(Hash::check('my-own-pass3', $faculty->password));
        $this->get(route('faculty.dashboard'))->assertOk();
    }

    public function test_the_new_password_cannot_be_the_temporary_one(): void
    {
        $this->setTemporary($this->faculty);
        $this->flushSession();
        auth()->logout();
        $this->signIn();

        $this->put(route('password.change.update'), ['password' => 'temp-pass9', 'password_confirmation' => 'temp-pass9'])
            ->assertSessionHasErrors(['password' => 'Choose a password different from the temporary one.']);
    }

    public function test_without_a_temporary_password_this_page_cannot_change_the_password(): void
    {
        $this->actingAs($this->faculty)
            ->put(route('password.change.update'), ['password' => 'hijacked-9', 'password_confirmation' => 'hijacked-9'])
            ->assertForbidden();

        $this->assertTrue(Hash::check('forgotten1', $this->faculty->fresh()->password));
        $this->actingAs($this->faculty)->get(route('password.change'))->assertRedirect(route('faculty.dashboard'));
    }

    public function test_an_unverified_user_changes_the_password_first_then_verifies(): void
    {
        $this->faculty->forceFill(['email_verified_at' => null])->save();
        $this->setTemporary($this->faculty);
        $this->flushSession();
        auth()->logout();

        $this->signIn()->assertRedirect(route('password.change'));
        $this->put(route('password.change.update'), ['password' => 'my-own-pass3', 'password_confirmation' => 'my-own-pass3'])
            ->assertRedirect(route('email.verify'));
    }

    public function test_accounts_the_admin_creates_must_change_their_password(): void
    {
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);

        $this->actingAs($this->admin)->post(route('admin.accounts.store'), [
            'role' => 'staff', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana@tcc.edu.ph', 'password' => 'temp-pass9',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(User::query()->where('email', 'ana@tcc.edu.ph')->sole()->must_change_password);
    }

    public function test_the_app_sees_the_flag(): void
    {
        $this->setTemporary($this->faculty);

        $this->postJson(route('api.login'), ['login' => 'maria@tcc.edu.ph', 'password' => 'temp-pass9', 'device_name' => 'Phone'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }
}
