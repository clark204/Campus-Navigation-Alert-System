<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin > Accounts > Edit details (Admin\AccountController@edit, @update): name, ID, program and email.
 */
class AccountEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
    }

    private function student(): User
    {
        return User::factory()->create([
            'role' => UserRole::Student, 'first_name' => 'Juan', 'middle_name' => 'C', 'last_name' => 'Reyes',
            'school_id' => '20260001', 'program_code' => 'BSIT', 'email' => 'juan@tcc.edu.ph',
        ]);
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    private function update(User $account, array $changes): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.accounts.update', $account), [
            'first_name' => $account->first_name,
            'middle_name' => $account->middle_name,
            'last_name' => $account->last_name,
            'school_id' => $account->school_id,
            'program_code' => $account->program_code,
            'email' => $account->email,
            ...$changes,
        ]);
    }

    public function test_the_edit_page_shows_the_current_details(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)->get(route('admin.accounts.show', $student))
            ->assertSee(route('admin.accounts.edit', $student));

        $this->actingAs($this->admin)->get(route('admin.accounts.edit', $student))
            ->assertOk()
            ->assertSee('value="Juan"', false)
            ->assertSee('value="20260001"', false)
            ->assertSee('value="juan@tcc.edu.ph"', false)
            ->assertSee('<option value="BSIT" selected>', false);
    }

    public function test_the_admin_fixes_a_name_id_and_program(): void
    {
        $student = $this->student();

        $this->update($student, ['first_name' => ' Juan Miguel ', 'school_id' => '20260002', 'program_code' => 'BSBA'])
            ->assertRedirect(route('admin.accounts.show', $student))
            ->assertSessionHas('status', 'Juan Miguel Reyes’s details are saved.');

        $student->refresh();
        $this->assertSame('Juan Miguel', $student->first_name);
        $this->assertSame('20260002', $student->school_id);
        $this->assertSame('BSBA', $student->program_code);
        $this->assertTrue($student->hasVerifiedEmail());
    }

    public function test_a_new_email_must_be_confirmed_by_its_owner_at_the_next_sign_in(): void
    {
        $student = $this->student();

        $this->update($student, ['email' => 'Juan.Reyes@TCC.edu.ph'])
            ->assertSessionHas('status', 'Saved. Juan will confirm juan.reyes@tcc.edu.ph with a code the next time they sign in.');

        $student->refresh();
        $this->assertSame('juan.reyes@tcc.edu.ph', $student->email);
        $this->assertTrue($student->needsEmailVerification());
    }

    public function test_the_same_rules_as_creating_an_account_apply(): void
    {
        $student = $this->student();
        User::factory()->create(['email' => 'taken@tcc.edu.ph', 'school_id' => '20269999']);

        $this->update($student, ['email' => 'taken@tcc.edu.ph', 'school_id' => '20269999', 'program_code' => null, 'last_name' => ''])
            ->assertSessionHasErrors([
                'email' => 'Another account already uses this email.',
                'school_id' => 'Another account already has this ID.',
                'program_code' => 'Choose the student’s program.',
                'last_name' => 'Enter their last name.',
            ]);

        $this->assertSame('juan@tcc.edu.ph', $student->fresh()->email);
    }

    public function test_staff_have_no_program(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'program_code' => null]);

        $this->update($staff, ['program_code' => 'BSIT'])
            ->assertSessionHasErrors(['program_code' => 'Staff don’t have a program.']);
    }

    public function test_admin_accounts_are_not_edited_here(): void
    {
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($this->admin)->get(route('admin.accounts.edit', $otherAdmin))->assertForbidden();
        $this->update($otherAdmin, ['first_name' => 'Changed'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.accounts.show', $otherAdmin))
            ->assertDontSee(route('admin.accounts.edit', $otherAdmin));
    }

    public function test_only_admins_can_edit_accounts(): void
    {
        $student = $this->student();

        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))
            ->put(route('admin.accounts.update', $student), ['first_name' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame('Juan', $student->fresh()->first_name);
    }
}
