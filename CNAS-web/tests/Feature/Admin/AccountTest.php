<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AccountController;
use App\Models\Alert;
use App\Models\ClassSchedule;
use App\Models\Concern;
use App\Models\LoginActivity;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Admin > Accounts: the list with filters and search, creating accounts, trusting faculty and staff to send alerts,
 * and deleting an account after typing its email.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Ada', 'last_name' => 'Admin']);
    }

    /**
     * @return array<string, mixed>
     */
    private function newFaculty(array $overrides = []): array
    {
        return [
            'role' => 'faculty',
            'first_name' => ' Maria ',
            'middle_name' => '',
            'last_name' => 'Santos',
            'email' => ' Maria.Santos@TCC.edu.ph ',
            'school_id' => '',
            'program_code' => 'BSIT',
            'password' => 'temporary12',
            ...$overrides,
        ];
    }

    #[TestWith(['get', 'admin.accounts.index'])]
    #[TestWith(['get', 'admin.accounts.create'])]
    #[TestWith(['post', 'admin.accounts.store'])]
    public function test_non_admins_are_forbidden(string $method, string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->{$method}(route($route))->assertForbidden();
    }

    public function test_the_list_shows_everyone_newest_first_with_counts_by_role(): void
    {
        $admin = $this->admin();
        User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Older', 'last_name' => 'Student', 'created_at' => now()->subDays(2)]);
        User::factory()->trustedForAlerts()->create(['role' => UserRole::Staff, 'first_name' => 'Newer', 'last_name' => 'Guard', 'created_at' => now()->subDay()]);

        $this->actingAs($admin)
            ->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('aria-label="1 students, 0 faculty, 1 staff, 1 admins"', false)
            ->assertSeeInOrder(['Everyone', 'Ada Admin', 'Newer Guard', 'Can send emergency alerts', 'Older Student'])
            ->assertSeeInOrder(['Can send alerts', '1'], false);
    }

    public function test_the_list_filters_by_role_and_by_who_can_send_alerts(): void
    {
        $admin = $this->admin();
        User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Student']);
        User::factory()->trustedForAlerts()->create(['role' => UserRole::Staff, 'first_name' => 'Gina', 'last_name' => 'Guard']);
        User::factory()->create(['role' => UserRole::Staff, 'first_name' => 'Rita', 'last_name' => 'Registrar']);
        // Trust left on a student account never counts.
        User::factory()->trustedForAlerts()->create(['role' => UserRole::Student, 'first_name' => 'Not', 'last_name' => 'Trusted']);

        $this->actingAs($admin)->get(route('admin.accounts.index', ['role' => 'staff']))
            ->assertSee('Gina Guard')->assertSee('Rita Registrar')->assertDontSee('Juan Student');

        $this->actingAs($admin)->get(route('admin.accounts.index', ['trusted' => 1]))
            ->assertSee('Gina Guard')->assertDontSee('Rita Registrar')->assertDontSee('Not Trusted');
    }

    public function test_the_list_searches_by_name_school_id_or_email(): void
    {
        $admin = $this->admin();
        User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Reyes', 'school_id' => '20260001']);
        User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'school_id' => '20260002']);

        $this->actingAs($admin)->get(route('admin.accounts.index', ['q' => '20260001']))
            ->assertSee('Juan Reyes')->assertDontSee('Ana Cruz');
        $this->actingAs($admin)->get(route('admin.accounts.index', ['q' => 'nobody-here']))
            ->assertSee('No one matches “nobody-here”.', false);
    }

    public function test_the_list_shows_ten_per_page_with_page_links(): void
    {
        $admin = $this->admin();
        User::factory()->count(14)->create(['role' => UserRole::Student, 'created_at' => now()->subDay()]);

        $this->actingAs($admin)->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('Showing 1 to 10 of 15')
            ->assertSee('href="'.route('admin.accounts.index', ['page' => 2]).'" rel="next"', false);

        $this->actingAs($admin)->get(route('admin.accounts.index', ['page' => 2]))
            ->assertSee('Showing 11 to 15 of 15')
            ->assertSee('<span aria-current="page" class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-sm font-semibold transition border-primary bg-primary text-white">2</span>', false);
    }

    public function test_a_single_page_still_shows_where_you_are(): void
    {
        $this->actingAs($this->admin())->get(route('admin.accounts.index'))
            ->assertSee('Showing 1 to 1 of 1')
            ->assertSee('aria-disabled="true">Next</span>', false);
    }

    public function test_the_live_search_gets_only_the_results_part(): void
    {
        $admin = $this->admin();
        // A surname no random factory email or name contains.
        User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Quibuyen']);

        $response = $this->actingAs($admin)
            ->withHeader(AccountController::RESULTS_HEADER, '1')
            ->get(route('admin.accounts.index', ['q' => 'quibuyen']))
            ->assertOk()
            ->assertSee('Juan Quibuyen')
            ->assertSee('Showing 1 to 1 of 1')
            ->assertDontSee('Ada Admin');

        $this->assertStringNotContainsString('<html', $response->getContent());
        $this->assertStringNotContainsString('Create account', $response->getContent());
    }

    public function test_the_admin_creates_a_faculty_account_that_can_sign_in(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.accounts.store'), $this->newFaculty(['trusted_for_alerts' => '1']))
            ->assertSessionHasNoErrors();

        $faculty = User::query()->where('email', 'maria.santos@tcc.edu.ph')->sole();
        $this->assertSame(UserRole::Faculty, $faculty->role);
        $this->assertSame('Maria', $faculty->first_name);
        $this->assertNull($faculty->middle_name);
        $this->assertNull($faculty->school_id);
        $this->assertSame('BSIT', $faculty->program_code);
        $this->assertTrue(Hash::check('temporary12', $faculty->password));
        $this->assertNotNull($faculty->created_at);
        $this->assertTrue($faculty->canSendAlerts());
        $this->assertNull($faculty->verified_at);
    }

    public function test_after_creating_the_admin_lands_on_the_new_account(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.accounts.store'), $this->newFaculty())
            ->assertRedirect(route('admin.accounts.show', User::query()->where('email', 'maria.santos@tcc.edu.ph')->sole()))
            ->assertSessionHas('status', 'Maria Santos’s account is ready. Give them their email and the temporary password; they choose their own when they first sign in.');
    }

    public function test_a_student_needs_an_eight_digit_id_and_a_program_and_is_never_trusted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->newFaculty(['role' => 'student', 'school_id' => '123', 'program_code' => '']))
            ->assertSessionHasErrors([
                'school_id' => 'A student ID has 8 digits, like 20260412.',
                'program_code' => 'Choose the student’s program.',
            ]);

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->newFaculty(['role' => 'student', 'school_id' => '20261234', 'trusted_for_alerts' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertNull(User::query()->where('school_id', '20261234')->sole()->alert_trusted_at);
    }

    public function test_admin_accounts_cannot_be_created_here(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.accounts.store'), $this->newFaculty(['role' => 'admin']))
            ->assertSessionHasErrors(['role' => 'Choose faculty, staff or student.']);

        $this->assertDatabaseMissing('users', ['email' => 'maria.santos@tcc.edu.ph']);
    }

    public function test_an_email_or_id_already_in_use_is_refused(): void
    {
        User::factory()->create(['email' => 'maria.santos@tcc.edu.ph', 'school_id' => 'EMP-7']);

        $this->actingAs($this->admin())
            ->post(route('admin.accounts.store'), $this->newFaculty(['school_id' => 'EMP-7']))
            ->assertSessionHasErrors([
                'email' => 'Another account already uses this email.',
                'school_id' => 'Another account already has this ID.',
            ]);
    }

    public function test_a_weak_temporary_password_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.accounts.store'), $this->newFaculty(['password' => 'short']))
            ->assertSessionHasErrors('password');
    }

    public function test_the_account_page_shows_activity_and_what_deleting_removes(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Reyes', 'email' => 'juan@tcc.edu.ph']);
        LoginActivity::factory()->count(3)->for($student)->create();
        ClassSchedule::factory()->count(2)->for($student)->create();

        $this->actingAs($this->admin())
            ->get(route('admin.accounts.show', $student))
            ->assertOk()
            ->assertSeeInOrder(['Juan Reyes', 'Sign-ins', '3'])
            ->assertSeeInOrder(['Danger zone', 'Delete this account', '3', 'sign-ins in the history', '2', 'classes in their schedule'])
            ->assertSee('data-expected="juan@tcc.edu.ph"', false)
            ->assertSee('Only faculty and staff can be trusted to send alerts.');
    }

    public function test_admin_accounts_have_no_delete_button(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('admin.accounts.show', $otherAdmin))
            ->assertSee('Administrator accounts can’t be deleted here', false)
            ->assertDontSee('data-account-delete', false);
    }

    public function test_the_admin_trusts_and_untrusts_a_staff_member(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'first_name' => 'Gina', 'last_name' => 'Guard']);

        $this->actingAs($admin)->from(route('admin.accounts.show', $staff))
            ->patch(route('admin.accounts.trust', $staff), ['trusted' => '1'])
            ->assertRedirect(route('admin.accounts.show', $staff))
            ->assertSessionHas('status', 'Gina Guard can now send emergency alerts from their portal.');
        $this->assertTrue($staff->fresh()->canSendAlerts());

        $this->actingAs($admin)->patch(route('admin.accounts.trust', $staff), ['trusted' => '0'])
            ->assertSessionHas('status', 'Gina Guard can no longer send emergency alerts.');
        $this->assertFalse($staff->fresh()->canSendAlerts());
    }

    public function test_a_student_cannot_be_trusted(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->actingAs($this->admin())->patch(route('admin.accounts.trust', $student), ['trusted' => '1'])->assertStatus(422);

        $this->assertNull($student->fresh()->alert_trusted_at);
    }

    public function test_a_wrong_confirmation_deletes_nothing(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'email' => 'juan@tcc.edu.ph']);

        $this->actingAs($this->admin())
            ->delete(route('admin.accounts.destroy', $student), ['confirm_email' => 'juan@tcc.edu'])
            ->assertRedirect(route('admin.accounts.show', $student).'#danger-zone')
            ->assertSessionHasErrors(['confirm_email' => 'That doesn’t match. Type juan@tcc.edu.ph exactly to delete this account.'], errorBag: 'deleteAccount');

        $this->assertModelExists($student);
    }

    public function test_typing_the_email_deletes_the_account_and_keeps_what_others_need(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Reyes', 'email' => 'juan@tcc.edu.ph']);
        $student->createToken('phone');
        ClassSchedule::factory()->for($student)->create();
        $concern = Concern::factory()->for($student)->create();
        $alert = Alert::factory()->create(['created_by' => $student->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.accounts.destroy', $student), ['confirm_email' => ' JUAN@tcc.edu.ph '])
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHas('status', 'Juan Reyes’s account was deleted.');

        $this->assertModelMissing($student);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('class_schedules', 0);
        $this->assertNull($concern->fresh()->user_id);
        $this->assertNull($alert->fresh()->created_by);
    }

    public function test_admins_and_your_own_account_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->delete(route('admin.accounts.destroy', $otherAdmin), ['confirm_email' => $otherAdmin->email])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.accounts.destroy', $admin), ['confirm_email' => $admin->email])->assertForbidden();

        $this->assertModelExists($otherAdmin);
        $this->assertModelExists($admin);
    }

    public function test_the_admin_search_opens_a_persons_account_page(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Reyes']);

        $this->actingAs($this->admin())
            ->getJson(route('admin.search.suggestions', ['q' => 'Juan Reyes']))
            ->assertOk()
            ->assertJsonPath('groups.0.label', 'People')
            ->assertJsonPath('groups.0.results.0.url', route('admin.accounts.show', $student));
    }
}
