<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Concern;
use App\Models\ConcernReply;
use App\Models\User;
use App\Notifications\ConcernReceived;
use App\Notifications\ConcernReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Help & contact and the bell for the mobile app: sending a concern (POST /api/concerns, the web's ConcernController),
 * "My messages" (GET /api/concerns) and the notifications (/api/notifications).
 */
class ConcernApiTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => UserRole::Student, 'email' => 'student@tcc.edu.ph']);
    }

    private function replyTo(User $user, string $message = 'We fixed the map.'): ConcernReply
    {
        $concern = Concern::factory()->for($user)->create(['email' => $user->email, 'message' => 'ComLab 3 is in the wrong place.']);
        $reply = ConcernReply::factory()->for($concern)->create(['message' => $message]);
        $user->notify(new ConcernReplied($reply));

        return $reply;
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson(route('api.concerns'))->assertUnauthorized();
        $this->getJson(route('api.notifications.index'))->assertUnauthorized();
    }

    public function test_sending_a_concern_goes_to_every_admin_and_replies_to_the_account_email(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $student = $this->student();
        Sanctum::actingAs($student);

        $this->postJson(route('api.concerns.store'), ['topic' => 'map', 'message' => 'ComLab 3 is in the wrong place.', 'email' => 'other@example.com'])
            ->assertCreated()
            ->assertJsonPath('message', 'Sent. The administrator will reply to student@tcc.edu.ph.')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.topic_label', 'The map or directions')
            ->assertJsonPath('data.replies', []);

        $this->assertDatabaseHas('concerns', ['user_id' => $student->id, 'email' => 'student@tcc.edu.ph', 'topic' => 'map']);
        Notification::assertSentTo($admin, ConcernReceived::class);
    }

    public function test_a_concern_uses_the_web_forms_messages(): void
    {
        Sanctum::actingAs($this->student());

        $this->postJson(route('api.concerns.store'), ['topic' => 'nope', 'message' => 'Too short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'topic' => 'Choose what your message is about.',
                'message' => 'Tell us a little more, at least 10 characters.',
            ]);
    }

    public function test_sending_is_limited_to_five_every_ten_minutes(): void
    {
        Sanctum::actingAs($this->student());
        $concern = ['topic' => 'other', 'message' => 'A question about the campus.'];

        for ($sent = 0; $sent < 5; $sent++) {
            $this->postJson(route('api.concerns.store'), $concern)->assertCreated();
        }

        $this->postJson(route('api.concerns.store'), $concern)->assertTooManyRequests();
    }

    public function test_my_messages_lists_the_newest_five_with_replies_and_marks_reply_notifications_read(): void
    {
        $student = $this->student();
        $this->replyTo($student);
        $this->travel(1)->minutes();
        Concern::factory()->for($student)->count(4)->create();
        $this->travel(1)->minutes();
        $newest = Concern::factory()->for($student)->resolved()->create(['message' => 'Thanks, it works now.']);
        Concern::factory()->create(['message' => 'Not mine.']);

        Sanctum::actingAs($student);

        $response = $this->getJson(route('api.concerns'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.0.status', 'resolved')
            ->assertJsonPath('meta.email', 'student@tcc.edu.ph');

        $this->assertSame(['account', 'schedule', 'map', 'alerts', 'suggestion', 'other'], $response->json('meta.topics.*.value'));
        $this->assertNotContains('Not mine.', $response->json('data.*.message'));
        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_the_replies_show_under_their_concern(): void
    {
        $student = $this->student();
        $this->replyTo($student, 'We fixed the map.');
        Sanctum::actingAs($student);

        $this->getJson(route('api.concerns'))
            ->assertJsonPath('data.0.message', 'ComLab 3 is in the wrong place.')
            ->assertJsonPath('data.0.replies.0.message', 'We fixed the map.');
    }

    public function test_the_bell_lists_the_unread_count_and_the_notifications(): void
    {
        $student = $this->student();
        $reply = $this->replyTo($student);
        Sanctum::actingAs($student);

        $this->getJson(route('api.notifications.index'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.kind', 'concern_reply')
            ->assertJsonPath('data.0.concern_id', $reply->concern_id)
            ->assertJsonPath('data.0.title', 'The administrator replied to your message')
            ->assertJsonPath('data.0.body', 'We fixed the map.')
            ->assertJsonPath('data.0.read', false);
    }

    public function test_a_notification_is_marked_read_one_at_a_time_or_all_together(): void
    {
        $student = $this->student();
        $this->replyTo($student);
        $this->replyTo($student, 'And the clinic too.');
        [$first] = $student->notifications()->get();
        Sanctum::actingAs($student);

        $this->postJson(route('api.notifications.read', $first->id))->assertOk()->assertExactJson(['unread_count' => 1]);
        $this->postJson(route('api.notifications.read-all'))->assertOk()->assertExactJson(['unread_count' => 0]);
        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_nobody_reads_another_persons_notification(): void
    {
        $theirs = $this->student();
        $this->replyTo($theirs);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Faculty]));

        $this->postJson(route('api.notifications.read', $theirs->notifications()->sole()->id))->assertNotFound();
        $this->assertSame(1, $theirs->unreadNotifications()->count());
    }
}
