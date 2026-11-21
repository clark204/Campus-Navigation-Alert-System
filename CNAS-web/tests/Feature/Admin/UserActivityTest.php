<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * "User activity" on the admin dashboard: who's online now and the sign-in history.
 */
class UserActivityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['first_name' => 'Campus', 'last_name' => 'Admin', 'role' => UserRole::Admin]);
    }

    private function seenMinutesAgo(int $minutes, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['last_seen_at' => now()->subMinutes($minutes)])->save();

        return $user;
    }

    /**
     * The "User activity" section only: the same names also appear under "Recent sign-ups".
     *
     * @param  array<string, string>  $filters
     */
    private function dashboard(array $filters = []): TestResponse
    {
        $html = $this->actingAs($this->admin())->get(route('admin.dashboard', $filters))->assertOk()->getContent();

        return TestResponse::fromBaseResponse(response(substr($html, strpos($html, 'id="user-activity"'))));
    }

    public function test_active_now_lists_users_seen_in_the_last_five_minutes_with_their_device(): void
    {
        $this->freezeSecond();
        $online = $this->seenMinutesAgo(2, ['first_name' => 'Angelica', 'last_name' => 'Dela Cruz', 'role' => UserRole::Student]);
        LoginActivity::factory()->for($online)->fromMobileApp()->create();
        $this->seenMinutesAgo(6, ['first_name' => 'Offline', 'last_name' => 'Person']);

        $this->dashboard()
            ->assertSeeInOrder(['User activity', 'Active now', '1', 'online in the last 5 minutes'])
            ->assertSeeInOrder(['Angelica Dela Cruz', 'Student', 'Chrome on Android', 'seen 2 minutes ago'])
            ->assertDontSee('Offline Person');
    }

    public function test_recent_sign_ins_show_user_role_channel_device_ip_and_times_latest_first(): void
    {
        $this->travelTo('2026-11-08 06:14:00'); // 2:14 PM campus time.
        $student = User::factory()->create(['first_name' => 'Angelica', 'last_name' => 'Dela Cruz', 'school_id' => '20260412', 'role' => UserRole::Student]);
        LoginActivity::factory()->for($student)->signedOut()->create([
            'ip_address' => '203.0.113.7',
            'logged_in_at' => now()->subHour(),
            'logged_out_at' => now()->subMinutes(30),
        ]);
        LoginActivity::factory()->for($student)->fromMobileApp()->create(['ip_address' => '198.51.100.4']);

        $this->dashboard()->assertSeeInOrder([
            'Recent sign-ins',
            'Angelica Dela Cruz', '20260412', 'Student', 'Mobile app', 'Chrome on Android', 'Phone', '198.51.100.4', 'Nov 8, 2:14 PM', 'Not signed out',
            'Angelica Dela Cruz', '20260412', 'Student', 'Web', 'Chrome on Windows', 'Desktop', '203.0.113.7', 'Nov 8, 1:14 PM', 'Nov 8, 1:44 PM',
        ]);
    }

    public function test_recent_sign_ins_are_paginated_ten_per_page(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        LoginActivity::factory()->for($student)->create(['ip_address' => '192.0.2.99', 'logged_in_at' => now()->subDays(2)]);
        LoginActivity::factory()->count(10)->for($student)->create(['ip_address' => '192.0.2.1']);

        $this->dashboard()
            ->assertSee('Showing 1–10 of 11')
            ->assertDontSee('192.0.2.99')
            ->assertSee(route('admin.dashboard', ['page' => 2]).'#user-activity', false);

        $this->dashboard(['page' => '2'])
            ->assertSee('Showing 11–11 of 11')
            ->assertSee('192.0.2.99');
    }

    public function test_filtering_by_role_narrows_both_lists(): void
    {
        $student = $this->seenMinutesAgo(1, ['first_name' => 'Stella', 'last_name' => 'Student', 'role' => UserRole::Student]);
        $faculty = $this->seenMinutesAgo(1, ['first_name' => 'Felix', 'last_name' => 'Faculty', 'role' => UserRole::Faculty]);
        LoginActivity::factory()->for($student)->create();
        LoginActivity::factory()->for($faculty)->create();

        $this->dashboard(['role' => 'faculty'])
            ->assertSee('Felix Faculty')
            ->assertDontSee('Stella Student')
            ->assertSee('<option value="faculty" selected>', false);
    }

    #[TestWith(['dela cruz'])]
    #[TestWith(['20260412'])]
    #[TestWith(['angelica@tcc.edu.ph'])]
    public function test_searching_finds_users_by_name_student_id_or_email(string $search): void
    {
        $match = User::factory()->create([
            'first_name' => 'Angelica', 'last_name' => 'Dela Cruz', 'school_id' => '20260412', 'email' => 'angelica@tcc.edu.ph',
        ]);
        $other = User::factory()->create(['first_name' => 'Mark', 'last_name' => 'Sabado', 'school_id' => '20260409', 'email' => 'mark@tcc.edu.ph']);
        LoginActivity::factory()->for($match)->create();
        LoginActivity::factory()->for($other)->create();

        $this->dashboard(['q' => $search])
            ->assertSee('Angelica Dela Cruz')
            ->assertDontSee('Mark Sabado');
    }

    public function test_an_empty_history_says_so(): void
    {
        $this->dashboard()
            ->assertSee('No one is online right now.')
            ->assertSee('No one has signed in yet.');
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    public function test_non_admins_cannot_see_user_activity(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('admin.dashboard'))
            ->assertForbidden()
            ->assertDontSee('User activity');
    }
}
