<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Concern;
use App\Models\ConcernReply;
use App\Models\User;
use App\Notifications\ConcernReceived;
use App\Notifications\ConcernReplied;
use App\Services\AccountDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The notification bell in the top bar (partials/notification-bell, NotificationController), for the admin and the
 * portals.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function studentWithReply(): User
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $concern = Concern::factory()->for($student)->create(['email' => $student->email]);
        $reply = ConcernReply::factory()->for($concern)->create(['message' => 'We fixed the map.']);
        $student->notify(new ConcernReplied($reply));

        return $student;
    }

    public function test_the_portal_bell_shows_the_unread_count_and_the_notification(): void
    {
        $student = $this->studentWithReply();

        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertSee('aria-label="Notifications, 1 unread"', false)
            ->assertSeeInOrder(['data-notification-menu', 'The administrator replied to your message', 'We fixed the map.'], escape: false);
    }

    public function test_the_admin_bell_shows_new_concerns(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $admin->notify(new ConcernReceived(Concern::factory()->create(['message' => 'Where is the clinic?'])));

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('aria-label="Notifications, 1 unread"', false)
            ->assertSee('New message from A visitor')
            ->assertSee('Where is the clinic?');
    }

    public function test_an_empty_bell_says_so(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))->get(route('faculty.dashboard'))
            ->assertSee('aria-label="Notifications"', false)
            ->assertSee('You’re all caught up');
    }

    public function test_opening_a_notification_marks_it_read_and_goes_where_it_points(): void
    {
        $student = $this->studentWithReply();
        $notification = $student->notifications()->sole();

        $this->actingAs($student)->get(route('notifications.open', $notification->id))
            ->assertRedirect($notification->data['url']);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_nobody_can_open_someone_elses_notification(): void
    {
        $notification = $this->studentWithReply()->notifications()->sole();

        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('notifications.open', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read(): void
    {
        $student = $this->studentWithReply();

        $this->actingAs($student)->from(route('student.dashboard'))->post(route('notifications.read-all'))
            ->assertRedirect(route('student.dashboard'));

        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_deleting_an_account_deletes_its_notifications(): void
    {
        $student = $this->studentWithReply();

        app(AccountDeletion::class)->delete($student);

        $this->assertDatabaseCount('notifications', 0);
    }
}
