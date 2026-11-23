<?php

namespace Tests\Feature\Api;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\EmailCodes;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Admin > Accounts for the mobile app (/api/admin/accounts), with the web's requests and rules (AccountAdministration):
 * students, faculty and staff get 403, and admin accounts are never edited, reset or deleted.
 */
class AdminAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Ada', 'last_name' => 'Admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newFaculty(array $overrides = []): array
    {
        return [
            'role' => 'faculty', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'Maria.Santos@tcc.edu.ph',
            'school_id' => 'EMP-0042', 'program_code' => null, 'password' => 'temporary1', 'trusted_for_alerts' => true,
            ...$overrides,
        ];
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    public function test_only_the_admin_gets_in(UserRole $role): void
    {
        $someone = User::factory()->create(['role' => UserRole::Staff]);
        $user = User::factory()->create(['role' => $role]);
        $user->trustForAlerts(true);
        Sanctum::actingAs($user);

        $this->getJson(route('api.admin.accounts.index'))->assertForbidden();
        $this->getJson(route('api.admin.accounts.show', $someone))->assertForbidden();
        $this->postJson(route('api.admin.accounts.store'), $this->newFaculty())->assertForbidden();
        $this->patchJson(route('api.admin.accounts.trust', $someone), ['trusted' => true])->assertForbidden();
        $this->deleteJson(route('api.admin.accounts.destroy', $someone), ['confirm_email' => $someone->email])->assertForbidden();
        $this->assertModelExists($someone);
    }

    public function test_the_list_searches_and_filters_on_the_server(): void
    {
        User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Luna']);
        $trusted = User::factory()->create(['role' => UserRole::Staff, 'first_name' => 'Rosa', 'last_name' => 'Diaz']);
        $trusted->trustForAlerts(true);
        Sanctum::actingAs($this->admin);

        $this->getJson(route('api.admin.accounts.index', ['q' => 'luna']))->assertOk()->assertJsonPath('data.*.name', ['Juan Luna']);
        $this->getJson(route('api.admin.accounts.index', ['role' => 'staff']))->assertJsonPath('data.*.name', ['Rosa Diaz']);
        $this->getJson(route('api.admin.accounts.index', ['trusted' => 1]))
            ->assertJsonPath('data.*.name', ['Rosa Diaz'])
            ->assertJsonPath('data.0.trusted_for_alerts', true)
            ->assertJsonPath('meta.trusted_count', 1)
            ->assertJsonPath('meta.counts.student', 1);
    }

    public function test_the_admin_creates_a_faculty_account_with_a_temporary_password(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(route('api.admin.accounts.store'), $this->newFaculty())
            ->assertCreated()
            ->assertJsonPath('message', 'Maria Santos’s account is ready. Give them their email and the temporary password; they choose their own when they first sign in.')
            ->assertJsonPath('data.email', 'maria.santos@tcc.edu.ph')
            ->assertJsonPath('data.trusted_for_alerts', true)
            ->assertJsonPath('data.must_change_password', true);

        $this->assertTrue(Hash::check('temporary1', User::where('email', 'maria.santos@tcc.edu.ph')->sole()->password));
    }

    public function test_creating_uses_the_web_rules(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(route('api.admin.accounts.store'), $this->newFaculty(['role' => 'student', 'school_id' => '123', 'email' => $this->admin->email]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'school_id' => 'A student ID has 8 digits, like 20260412.',
                'program_code' => 'Choose the student’s program.',
                'email' => 'Another account already uses this email.',
            ]);
        $this->postJson(route('api.admin.accounts.store'), $this->newFaculty(['role' => 'admin']))
            ->assertJsonValidationErrors(['role' => 'Choose faculty, staff or student.']);
    }

    public function test_editing_a_new_email_makes_it_unverified_and_cancels_pending_codes(): void
    {
        Mail::fake();
        $student = User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT', 'school_id' => '20240001']);
        app(EmailCodes::class)->send($student, EmailCodePurpose::ChangeEmail, 'other@tcc.edu.ph');
        Sanctum::actingAs($this->admin);

        $this->putJson(route('api.admin.accounts.update', $student), [
            'first_name' => 'Juan', 'last_name' => 'Luna', 'email' => 'juan.luna@tcc.edu.ph', 'school_id' => '20240001', 'program_code' => 'BSIT',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Saved. Juan will confirm juan.luna@tcc.edu.ph with a code the next time they sign in.')
            ->assertJsonPath('data.email_verified', false);
        $this->assertDatabaseEmpty('email_codes');
    }

    public function test_admin_accounts_are_never_edited_reset_or_deleted_here(): void
    {
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($this->admin);

        $this->putJson(route('api.admin.accounts.update', $otherAdmin), ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@tcc.edu.ph'])->assertForbidden();
        $this->patchJson(route('api.admin.accounts.password', $otherAdmin), ['password' => 'temporary1'])->assertForbidden();
        $this->patchJson(route('api.admin.accounts.role', $otherAdmin), ['role' => 'staff'])->assertUnprocessable();
        $this->deleteJson(route('api.admin.accounts.destroy', $otherAdmin), ['confirm_email' => $otherAdmin->email])->assertForbidden();
        $this->deleteJson(route('api.admin.accounts.destroy', $this->admin), ['confirm_email' => $this->admin->email])->assertForbidden();
        $this->getJson(route('api.admin.accounts.show', $this->admin))->assertJsonPath('data.can_delete', false);

        $this->assertModelExists($otherAdmin);
        $this->assertSame(UserRole::Admin, $otherAdmin->fresh()->role);
    }

    public function test_trust_is_only_for_faculty_and_staff(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'first_name' => 'Rosa', 'last_name' => 'Diaz']);
        $student = User::factory()->create(['role' => UserRole::Student]);
        Sanctum::actingAs($this->admin);

        $this->patchJson(route('api.admin.accounts.trust', $staff), ['trusted' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Rosa Diaz can now send emergency alerts from their portal.')
            ->assertJsonPath('data.trusted_for_alerts', true);
        $this->patchJson(route('api.admin.accounts.trust', $student), ['trusted' => true])->assertUnprocessable();
        $this->assertFalse($student->fresh()->isTrustedForAlerts());
    }

    public function test_a_temporary_password_signs_them_out_everywhere(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $staff->createToken('Their phone');
        Sanctum::actingAs($this->admin);

        $this->patchJson(route('api.admin.accounts.password', $staff), ['password' => 'letters'])
            ->assertJsonValidationErrors('password');
        $this->patchJson(route('api.admin.accounts.password', $staff), ['password' => 'temporary2'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->assertTrue(Hash::check('temporary2', $staff->fresh()->password));
        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_faculty_and_staff_switch_but_other_roles_do_not(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'first_name' => 'Rosa', 'last_name' => 'Diaz']);
        $student = User::factory()->create(['role' => UserRole::Student]);
        Sanctum::actingAs($this->admin);

        $this->patchJson(route('api.admin.accounts.role', $staff), ['role' => 'faculty'])
            ->assertOk()
            ->assertJsonPath('message', 'Rosa Diaz is now Faculty. They can add the classes they teach under My classes.');
        $this->patchJson(route('api.admin.accounts.role', $staff), ['role' => 'admin'])->assertJsonValidationErrors(['role' => 'Choose faculty or staff.']);
        $this->patchJson(route('api.admin.accounts.role', $student), ['role' => 'faculty'])->assertUnprocessable();
        $this->assertSame(UserRole::Student, $student->fresh()->role);
    }

    public function test_deleting_needs_the_accounts_email_typed(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'email' => 'rosa@tcc.edu.ph', 'first_name' => 'Rosa', 'last_name' => 'Diaz']);
        Sanctum::actingAs($this->admin);

        $this->deleteJson(route('api.admin.accounts.destroy', $staff), ['confirm_email' => 'rosa@tcc.edu'])
            ->assertJsonValidationErrors(['confirm_email' => 'That doesn’t match. Type rosa@tcc.edu.ph exactly to delete this account.']);
        $this->assertModelExists($staff);

        $this->deleteJson(route('api.admin.accounts.destroy', $staff), ['confirm_email' => ' ROSA@tcc.edu.ph '])
            ->assertOk()
            ->assertJsonPath('message', 'Rosa Diaz’s account was deleted.');
        $this->assertModelMissing($staff);
    }

    public function test_the_options_list_the_roles_and_programs(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson(route('api.admin.accounts.options'))->assertOk();
        $this->assertSame(['faculty', 'staff', 'student'], $response->json('roles.*.value'));
        $this->assertContains('BSIT', collect($response->json('colleges'))->flatMap(fn ($college) => array_column($college['programs'], 'code'))->all());
    }
}
