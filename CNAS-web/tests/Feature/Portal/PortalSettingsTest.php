<?php

namespace Tests\Feature\Portal;

use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Portal Settings (students and faculty): Profile, Account (email and password, the shared AccountController) and
 * Alerts tabs, and the top bar's account menu that links to them.
 */
class PortalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'current-pass1';

    private function student(): User
    {
        return User::factory()->create([
            'role' => UserRole::Student,
            'first_name' => 'Angelica',
            'middle_name' => 'Ramos',
            'last_name' => 'Dela Cruz',
            'school_id' => '20260412',
            'email' => 'angelica@tcc.edu.ph',
            'password' => self::CURRENT_PASSWORD,
        ]);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('student.settings'))->assertRedirect(route('login'));
    }

    #[TestWith([UserRole::Student, 'faculty.settings'])]
    #[TestWith([UserRole::Faculty, 'student.settings'])]
    #[TestWith([UserRole::Admin, 'student.settings'])]
    public function test_users_are_forbidden_from_another_portals_settings(UserRole $role, string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route($route))->assertForbidden();
    }

    public function test_the_profile_tab_opens_first_and_shows_the_cor_details(): void
    {
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        $student = $this->student();
        $student->forceFill(['program_code' => 'BSIT'])->save();

        $this->actingAs($student)
            ->get(route('student.settings'))
            ->assertOk()
            ->assertSee('id="tab-profile" role="tab" href="'.route('student.settings', ['tab' => 'profile']).'" aria-controls="profile"'."\n".'                    aria-selected="true"', false)
            ->assertSee('<section id="my-account" role="tabpanel" aria-labelledby="tab-my-account"  hidden', false)
            ->assertSeeInOrder(['Full name', 'Angelica Ramos Dela Cruz', 'School ID', '20260412', 'Program', 'College', 'College of Information Technology', 'Email', 'angelica@tcc.edu.ph'])
            ->assertSee('href="'.route('student.help', ['topic' => 'account']).'"', false);
    }

    #[TestWith(['my-account', 'Email and password'])]
    #[TestWith(['alerts', 'What each alert means'])]
    public function test_the_tab_in_the_query_opens(string $tab, string $heading): void
    {
        $this->actingAs($this->student())
            ->get(route('student.settings', ['tab' => $tab]))
            ->assertOk()
            ->assertSee('<section id="profile" role="tabpanel" aria-labelledby="tab-profile"  hidden', false)
            ->assertSee($heading);
    }

    public function test_the_alerts_tab_has_the_on_and_off_control_for_this_device(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.settings', ['tab' => 'alerts']))
            ->assertSee('data-push-alerts', false)
            ->assertSee('data-push-turn-on', false)
            ->assertSee('data-push-turn-off', false)
            ->assertDontSee('data-push-hide-states', false);
    }

    public function test_a_new_email_gets_a_code_and_returns_to_the_account_tab(): void
    {
        Mail::fake();
        $student = $this->student();

        $this->actingAs($student)
            ->put(route('student.settings.account.email'), ['email' => ' New.Me@TCC.edu.ph '])
            ->assertRedirect(route('student.settings', ['tab' => 'my-account']))
            ->assertSessionHas('status', 'We sent a code to new.me@tcc.edu.ph. Enter it below to finish changing your email.');

        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('new.me@tcc.edu.ph'));
        $this->assertSame('angelica@tcc.edu.ph', $student->fresh()->email);
    }

    public function test_an_invalid_email_returns_to_the_portal_account_tab_with_the_error(): void
    {
        $student = $this->student();
        User::factory()->create(['email' => 'taken@tcc.edu.ph']);

        $this->actingAs($student)
            ->from(route('student.settings'))
            ->put(route('student.settings.account.email'), ['email' => 'taken@tcc.edu.ph'])
            ->assertRedirect(route('student.settings', ['tab' => 'my-account']))
            ->assertSessionHasErrors(['email' => 'Another account already uses this email.'], errorBag: 'accountEmail');

        $this->assertSame('angelica@tcc.edu.ph', $student->fresh()->email);
    }

    public function test_staff_change_their_password_from_the_faculty_portal(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'password' => self::CURRENT_PASSWORD]);

        $this->actingAs($staff)
            ->put(route('faculty.settings.account.password'), [
                'current_password' => self::CURRENT_PASSWORD,
                'password' => 'brand-new-pass2',
                'password_confirmation' => 'brand-new-pass2',
            ])
            ->assertRedirect(route('faculty.settings', ['tab' => 'my-account']))
            ->assertSessionHas('status', 'Password changed. You were signed out everywhere else, including the mobile app.');

        $this->assertTrue(password_verify('brand-new-pass2', $staff->fresh()->password));
    }

    public function test_the_account_menu_links_to_the_settings_tabs_saved_places_and_help(): void
    {
        $this->actingAs($this->student())
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Account menu for Angelica Dela Cruz"', false)
            ->assertSeeInOrder([
                'angelica@tcc.edu.ph',
                route('student.settings', ['tab' => 'profile']),
                route('student.settings', ['tab' => 'my-account']),
                route('student.settings', ['tab' => 'alerts']),
                route('student.saved'),
                route('student.help'),
                'action="'.route('logout').'"',
            ], false);
    }

    public function test_the_admin_account_menu_links_to_the_admin_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.settings', ['tab' => 'my-account']), false)
            ->assertDontSee(route('student.settings'), false);
    }
}
