<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Path;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Accounts and Recent sign-ups cards on the admin overview use real users and colleges.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        // Wednesday 2:14 PM campus time; the week started Monday Nov 9, 00:00 campus time (Nov 8, 16:00 UTC).
        $this->travelTo('2026-11-11 06:14:00');
    }

    private function dashboard(): TestResponse
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'created_at' => now()->subMonth()]);

        return $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_accounts_are_counted_by_role_with_the_number_new_this_week(): void
    {
        User::factory()->count(3)->create(['role' => UserRole::Student, 'created_at' => now()->subDay()]);
        User::factory()->create(['role' => UserRole::Student, 'created_at' => '2026-11-08 15:59:00']); // Sunday night, last week.
        User::factory()->create(['role' => UserRole::Faculty, 'created_at' => '2026-11-08 16:00:00']); // Monday 00:00, this week.

        $this->dashboard()
            ->assertSeeInOrder(['Accounts', '6', 'registered, 4 this week'])
            ->assertSeeInOrder(['Students', '4', 'Faculty', '1', 'Staff', '0', 'Admins', '1'])
            ->assertSee('aria-label="Accounts by role: 4 students, 1 faculty, 0 staff, 1 admins"', false);
    }

    public function test_most_students_by_college_counts_students_only_and_skips_empty_colleges(): void
    {
        User::factory()->count(3)->create(['role' => UserRole::Student, 'program_code' => 'BSCRIM']);
        User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT']);
        User::factory()->count(5)->create(['role' => UserRole::Faculty, 'program_code' => 'BSM']);

        $this->dashboard()
            ->assertSeeInOrder(['Most students by college', 'College of Criminology', '3', 'College of Information Technology', '1', 'Recent sign-ups'])
            ->assertSee('style="width: 75%;', false)
            ->assertDontSee('College of Midwifery</span>', false);
    }

    public function test_recent_sign_ups_list_the_newest_five_non_admin_accounts(): void
    {
        User::factory()->create(['first_name' => 'Oldest', 'last_name' => 'Student', 'role' => UserRole::Student, 'created_at' => now()->subDays(10)]);
        foreach (range(1, 4) as $daysAgo) {
            User::factory()->create(['first_name' => "Day{$daysAgo}", 'last_name' => 'Student', 'role' => UserRole::Student, 'created_at' => now()->subDays($daysAgo)]);
        }
        User::factory()->create([
            'first_name' => 'Angelica', 'last_name' => 'Dela Cruz', 'school_id' => '20260412', 'program_code' => 'BSIT',
            'role' => UserRole::Student, 'created_at' => now(),
        ]);
        User::factory()->create(['first_name' => 'Another', 'last_name' => 'Admin', 'role' => UserRole::Admin, 'created_at' => now()]);

        $this->dashboard()
            ->assertSeeInOrder([
                'Recent sign-ups',
                'Angelica Dela Cruz', '20260412', 'College of Information Technology', 'Student', 'Today, 2:14 PM',
                'Day1 Student', 'Yesterday',
                'Day2 Student', 'Nov 9',
                'Day3 Student', 'Day4 Student',
                'User activity',
            ])
            ->assertDontSee('Oldest Student')
            ->assertDontSee('Another Admin');
    }

    public function test_an_account_without_a_creation_date_shows_as_unknown_instead_of_breaking_the_page(): void
    {
        // An account inserted straight into the database (no Eloquent timestamps), as happened on the dev database.
        $staff = User::factory()->create(['first_name' => 'Vidal', 'last_name' => 'Staff', 'role' => UserRole::Staff]);
        User::query()->whereKey($staff->id)->toBase()->update(['created_at' => null, 'updated_at' => null]);

        $this->dashboard()->assertSeeInOrder(['Recent sign-ups', 'Vidal Staff', 'Date unknown', 'User activity']);
    }

    public function test_campus_map_counts_come_from_the_map_tables(): void
    {
        $this->seed(CampusMapSeeder::class);

        $this->dashboard()
            ->assertViewHas('mapCounts', ['Buildings' => 13, 'Rooms' => 29, 'Walkways' => 11, 'Stairs' => 2])
            ->assertSeeInOrder(['Buildings', '13', 'Rooms', '29', 'Walkways', '11', 'Stairs', '2']);
    }

    public function test_upper_floor_corridors_are_not_counted_as_walkways(): void
    {
        $this->seed(CampusMapSeeder::class);
        Path::factory()->onFloor(2)->create();

        $this->dashboard()->assertViewHas('mapCounts', fn (array $counts) => $counts['Walkways'] === 11);
    }

    public function test_an_empty_map_counts_nothing(): void
    {
        $this->dashboard()->assertViewHas('mapCounts', ['Buildings' => 0, 'Rooms' => 0, 'Walkways' => 0, 'Stairs' => 0]);
    }

    public function test_an_empty_school_shows_empty_states(): void
    {
        $this->dashboard()
            ->assertSee('registered, 0 this week')
            ->assertSee('No students have registered yet.')
            ->assertSee('No one has registered yet.');
    }
}
