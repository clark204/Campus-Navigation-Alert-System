<?php

namespace Tests\Feature\Admin;

use App\Enums\ConcernTopic;
use App\Enums\UserRole;
use App\Mail\ConcernReplyMail;
use App\Models\Concern;
use App\Models\User;
use App\Notifications\ConcernReceived;
use App\Notifications\ConcernReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin > Concerns: replying. The reply is emailed to the concern's email and, when the sender has an account, shows
 * in their portal (notification bell and Help & contact). New concerns show in the admins' bell.
 */
class ConcernReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'email' => 'juan@tcc.edu.ph']);
    }

    private function studentConcern(): Concern
    {
        return Concern::factory()->for($this->student)->create(['email' => 'juan@tcc.edu.ph', 'topic' => ConcernTopic::Map, 'message' => 'The library is missing.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reply(Concern $concern, array $data): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.concerns.show', $concern))
            ->post(route('admin.concerns.reply', $concern), $data);
    }

    public function test_a_reply_is_emailed_shown_in_the_portal_and_resolves_the_concern(): void
    {
        $concern = $this->studentConcern();

        $this->reply($concern, ['message' => '  Added it, thanks!  ', 'resolve' => '1'])
            ->assertRedirect(route('admin.concerns.show', $concern))
            ->assertSessionHas('status', 'Reply sent to juan@tcc.edu.ph. It also shows in their portal.');

        $reply = $concern->replies()->sole();
        $this->assertSame('Added it, thanks!', $reply->message);
        $this->assertTrue($reply->author->is($this->admin));
        $this->assertTrue($concern->fresh()->isResolved());

        Mail::assertQueued(ConcernReplyMail::class, fn (ConcernReplyMail $mail) => $mail->hasTo('juan@tcc.edu.ph') && $mail->reply->is($reply));

        $notification = $this->student->notifications()->sole();
        $this->assertSame(ConcernReplied::class, $notification->type);
        $this->assertSame('The administrator replied to your message', $notification->data['title']);
        $this->assertSame('/student/help#concern-'.$concern->id, $notification->data['url']);
    }

    public function test_the_reply_email_shows_the_reply_and_the_original_message(): void
    {
        $concern = $this->studentConcern();
        $this->reply($concern, ['message' => "It's on the map now."]);

        $mail = Mail::queued(ConcernReplyMail::class)->sole();
        $mail->assertHasSubject('Re: your TCC NAV message (Map)');
        $mail->assertSeeInHtml('It&#039;s on the map now.', false);
        $mail->assertSeeInOrderInHtml(['The administrator replied', 'You wrote', 'The library is missing.', 'Open Help &amp; contact'], false);
        $mail->assertSeeInText("It's on the map now.");
    }

    public function test_leaving_mark_as_resolved_off_keeps_the_concern_open(): void
    {
        $concern = $this->studentConcern();

        $this->reply($concern, ['message' => 'Which building?', 'resolve' => '0']);

        $this->assertFalse($concern->fresh()->isResolved());
    }

    public function test_a_visitor_gets_the_reply_by_email_only(): void
    {
        $concern = Concern::factory()->create(['email' => 'visitor@example.com']);

        $this->reply($concern, ['message' => 'Thanks for writing.'])
            ->assertSessionHas('status', 'Reply sent to visitor@example.com.');

        Mail::assertQueued(ConcernReplyMail::class, fn (ConcernReplyMail $mail) => $mail->hasTo('visitor@example.com'));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_an_empty_reply_shows_an_error_under_that_concern(): void
    {
        $concern = $this->studentConcern();

        $this->reply($concern, ['message' => '   '])
            ->assertSessionHasErrors(['message' => 'Write your reply first.'], errorBag: 'reply'.$concern->id);

        $this->assertSame(0, $concern->replies()->count());
        Mail::assertNothingQueued();
    }

    public function test_only_admins_can_reply(): void
    {
        $concern = $this->studentConcern();

        $this->actingAs($this->student)->post(route('admin.concerns.reply', $concern), ['message' => 'Hi'])->assertForbidden();

        $this->assertSame(0, $concern->replies()->count());
    }

    public function test_the_conversation_shows_the_replies_in_order_and_the_list_shows_the_count(): void
    {
        $concern = $this->studentConcern();
        $this->reply($concern, ['message' => 'Added it, thanks!', 'resolve' => '0']);

        $this->actingAs($this->admin)->get(route('admin.concerns.show', $concern))
            ->assertSeeInOrder(['The library is missing.', 'You', 'to juan@tcc.edu.ph', 'Added it, thanks!', 'Write your reply']);
        $this->actingAs($this->admin)->get(route('admin.concerns.index'))
            ->assertSee('aria-label="2 messages"', false);
    }

    public function test_a_new_concern_shows_in_every_admins_bell_until_one_of_them_deals_with_it(): void
    {
        Notification::fake();
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($this->student)->post(route('concerns.store'), ['topic' => 'map', 'message' => 'The library is missing from the map.']);

        Notification::assertSentTo([$this->admin, $otherAdmin], ConcernReceived::class);
        Notification::assertNotSentTo($this->student, ConcernReceived::class);
    }

    public function test_replying_marks_the_new_concern_notification_read_for_every_admin(): void
    {
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->student)->post(route('concerns.store'), ['topic' => 'map', 'message' => 'The library is missing from the map.']);
        $concern = Concern::query()->sole();
        $this->assertSame(1, $otherAdmin->unreadNotifications()->count());

        $this->reply($concern, ['message' => 'Added it.']);

        $this->assertSame(0, $this->admin->unreadNotifications()->count());
        $this->assertSame(0, $otherAdmin->unreadNotifications()->count());
    }

    public function test_the_student_sees_the_reply_on_help_and_contact_which_marks_it_read(): void
    {
        $concern = $this->studentConcern();
        $this->reply($concern, ['message' => 'Added it, thanks!']);
        $this->flushSession();

        $this->actingAs($this->student)->get(route('student.help'))
            ->assertSee('id="concern-'.$concern->id.'"', false)
            ->assertSeeInOrder(['The library is missing.', 'The administrator replied', 'Added it, thanks!']);

        $this->assertSame(0, $this->student->unreadNotifications()->count());
    }
}
