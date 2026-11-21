<?php

namespace Tests\Feature\Portal;

use App\Enums\ConcernTopic;
use App\Enums\UserRole;
use App\Http\Controllers\Portal\HelpController;
use App\Models\Concern;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The portal's Help & contact page: the concern form, the user's own messages with their status, and the
 * administrator's contact details and emergency numbers. Sending is Tests\Feature\ConcernTest.
 */
class HelpPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('student.help'))->assertRedirect(route('login'));
    }

    #[TestWith([UserRole::Student, 'faculty.help'])]
    #[TestWith([UserRole::Faculty, 'student.help'])]
    #[TestWith([UserRole::Admin, 'student.help'])]
    public function test_users_are_forbidden_from_another_portals_help_page(UserRole $role, string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route($route))->assertForbidden();
    }

    public function test_staff_open_the_faculty_help_page_and_the_sidebar_marks_it(): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))
            ->get(route('faculty.help'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('faculty.help'), '/').'"\s+aria-current="page"/', $html);
    }

    public function test_the_form_sends_to_the_inbox_and_preselects_the_topic_from_the_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student, 'email' => 'juan@tcc.edu.ph']))
            ->get(route('student.help', ['topic' => 'account']))
            ->assertOk()
            ->assertSeeInOrder(['action="'.route('concerns.store').'"', 'Send a concern or suggestion', 'The administrator replies to', 'juan@tcc.edu.ph'], false)
            ->assertSee('value="account" required class="peer sr-only" checked', false)
            ->assertSee('value="map" required class="peer sr-only" >', false);
    }

    public function test_your_messages_lists_only_your_own_newest_first_with_their_status(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->travelTo('2026-11-17 02:00');
        Concern::factory()->for($student)->resolved()->create(['topic' => ConcernTopic::Map, 'message' => 'ComLab 3 pin is off.']);
        $this->travelTo('2026-11-18 02:00');
        Concern::factory()->for($student)->create(['topic' => ConcernTopic::Account, 'message' => 'Cannot sign in on my phone.']);
        Concern::factory()->for(User::factory()->create(['role' => UserRole::Student]))->create(['message' => 'Someone else wrote this.']);

        $this->actingAs($student)
            ->get(route('student.help'))
            ->assertOk()
            ->assertSeeInOrder([
                'Your messages',
                'My account or signing in', 'Open', 'Cannot sign in on my phone.', 'Nov 18, 2026',
                'The map or directions', 'Resolved', 'ComLab 3 pin is off.', 'Nov 17, 2026',
            ])
            ->assertDontSee('Someone else wrote this.');
    }

    public function test_your_messages_shows_only_the_newest_few(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        Concern::factory()->for($student)->create(['message' => 'The very first message.', 'created_at' => now()->subDay()]);
        Concern::factory()->for($student)->count(HelpController::RECENT_CONCERNS)->create();

        $this->actingAs($student)->get(route('student.help'))->assertOk()->assertDontSee('The very first message.');
    }

    public function test_your_messages_explains_itself_when_empty(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.help'))
            ->assertSee('Nothing sent yet. Messages you send show up here with their status.');
    }

    public function test_it_lists_the_emergency_numbers_the_admin_shows(): void
    {
        EmergencyContact::factory()->create(['name' => 'Campus security', 'phone' => '+63 912-000-1111']);
        EmergencyContact::factory()->hidden()->create(['name' => 'Hidden clinic']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.help'))
            ->assertSeeInOrder(['Emergency numbers', 'Campus security', 'href="tel:+639120001111"'], false)
            ->assertDontSee('Hidden clinic');
    }
}
