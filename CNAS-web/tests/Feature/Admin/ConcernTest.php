<?php

namespace Tests\Feature\Admin;

use App\Enums\ConcernTopic;
use App\Enums\UserRole;
use App\Models\Concern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin > Concerns: the inbox of concerns sent from Help & contact, with resolve and reopen. Sending them is
 * Tests\Feature\ConcernTest.
 */
class ConcernTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('admin.concerns.index'))->assertRedirect(route('login'));
    }

    public function test_non_admins_cannot_read_or_resolve_concerns(): void
    {
        $concern = Concern::factory()->create();
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->actingAs($student)->get(route('admin.concerns.index'))->assertForbidden();
        $this->actingAs($student)->patch(route('admin.concerns.resolve', $concern))->assertForbidden();

        $this->assertNull($concern->fresh()->resolved_at);
    }

    public function test_the_inbox_lists_open_concerns_newest_first_with_the_sender(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Reyes', 'school_id' => '20260001']);
        $this->travelTo('2026-11-18 01:00');
        Concern::factory()->create(['message' => 'The older one.', 'email' => 'visitor@example.com']);
        $this->travelTo('2026-11-18 02:00');
        Concern::factory()->for($student)->create(['message' => 'The newer one.', 'topic' => ConcernTopic::Map, 'email' => 'juan@tcc.edu.ph']);
        Concern::factory()->resolved()->create(['message' => 'Already handled.']);

        $newer = Concern::query()->where('message', 'The newer one.')->sole();

        // Inbox rows: sender, topic, the start of the message, and a link to the conversation.
        $this->actingAs($this->admin())
            ->get(route('admin.concerns.index'))
            ->assertOk()
            ->assertSeeInOrder(['Juan Reyes', 'Map', 'The newer one.', '10:00 AM', 'Visitor', 'The older one.'])
            ->assertSee(route('admin.concerns.show', $newer))
            ->assertDontSee('Already handled.');

        // The conversation: who sent it, when, and the reply box.
        $this->actingAs($this->admin())
            ->get(route('admin.concerns.show', $newer))
            ->assertOk()
            ->assertSeeInOrder(['The map or directions', 'Juan Reyes', 'juan@tcc.edu.ph', 'Nov 18, 2026, 10:00 AM', 'Student · 20260001', 'The newer one.', 'Your reply to Juan'])
            ->assertSee('Mark resolved');
    }

    public function test_opening_a_concern_marks_it_read_for_that_admin_only(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $this->post(route('concerns.store'), ['email' => 'visitor@example.com', 'topic' => 'map', 'message' => 'Where is the library?']);
        $concern = Concern::query()->sole();

        $this->actingAs($admin)->get(route('admin.concerns.index'))->assertSee('Unread: ', false);
        $this->actingAs($admin)->get(route('admin.concerns.show', $concern))->assertOk();
        $this->actingAs($admin)->get(route('admin.concerns.index'))->assertDontSee('Unread: ', false);

        $this->assertSame(0, $admin->unreadNotifications()->count());
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());
    }

    public function test_the_resolved_filter_lists_only_resolved_concerns(): void
    {
        Concern::factory()->create(['message' => 'Still open.']);
        $resolved = Concern::factory()->resolved()->create(['message' => 'Already handled.']);

        $this->actingAs($this->admin())
            ->get(route('admin.concerns.index', ['status' => 'resolved']))
            ->assertOk()
            ->assertSee('Already handled.')
            ->assertDontSee('Still open.');

        $this->actingAs($this->admin())->get(route('admin.concerns.show', $resolved))->assertSee('Reopen');
    }

    public function test_the_sidebar_shows_how_many_concerns_are_open(): void
    {
        Concern::factory()->count(3)->create();
        Concern::factory()->resolved()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Concerns', '3<span class="sr-only"> open</span>'], false);
    }

    public function test_the_admin_marks_a_concern_resolved_and_can_reopen_it(): void
    {
        $concern = Concern::factory()->create();
        $admin = $this->admin();
        $this->travelTo('2026-11-18 03:00');

        $this->actingAs($admin)
            ->from(route('admin.concerns.index'))
            ->patch(route('admin.concerns.resolve', $concern))
            ->assertRedirect(route('admin.concerns.index'))
            ->assertSessionHas('status', 'Marked as resolved.');
        $this->assertEquals(now(), $concern->fresh()->resolved_at);

        $this->actingAs($admin)
            ->from(route('admin.concerns.index', ['status' => 'resolved']))
            ->patch(route('admin.concerns.reopen', $concern))
            ->assertRedirect(route('admin.concerns.index', ['status' => 'resolved']))
            ->assertSessionHas('status', 'Moved back to open.');
        $this->assertNull($concern->fresh()->resolved_at);
    }

    public function test_a_concern_outlives_its_senders_account(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $concern = Concern::factory()->for($student)->create();

        $student->delete();

        $this->assertNull($concern->fresh()->user_id);
    }
}
