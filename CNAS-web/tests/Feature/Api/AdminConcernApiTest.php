<?php

namespace Tests\Feature\Api;

use App\Enums\ConcernTopic;
use App\Enums\UserRole;
use App\Mail\ConcernReplyMail;
use App\Models\Concern;
use App\Models\User;
use App\Notifications\ConcernReceived;
use App\Notifications\ConcernReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Admin > Concerns for the mobile app (/api/admin/concerns), the same inbox as the web (ConcernInbox).
 */
class AdminConcernApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Ada', 'last_name' => 'Admin']);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Juan', 'last_name' => 'Luna', 'email' => 'juan@tcc.edu.ph']);
    }

    private function concern(array $attributes = []): Concern
    {
        $concern = Concern::factory()->for($this->student)->create(['email' => 'juan@tcc.edu.ph', 'topic' => ConcernTopic::Map, 'message' => 'The library is missing.', ...$attributes]);
        $this->admin->notify(new ConcernReceived($concern));

        return $concern;
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    public function test_only_the_admin_gets_in(UserRole $role): void
    {
        $concern = $this->concern();
        $user = User::factory()->create(['role' => $role]);
        $user->trustForAlerts(true);
        Sanctum::actingAs($user);

        $this->getJson(route('api.admin.concerns.index'))->assertForbidden();
        $this->getJson(route('api.admin.concerns.show', $concern))->assertForbidden();
        $this->postJson(route('api.admin.concerns.reply', $concern), ['message' => 'Hi'])->assertForbidden();
        $this->patchJson(route('api.admin.concerns.resolve', $concern))->assertForbidden();
        $this->assertNull($concern->fresh()->resolved_at);
    }

    public function test_the_open_folder_lists_newest_first_with_unread_and_the_counts(): void
    {
        $older = $this->concern();
        $this->travel(1)->minutes();
        $newer = $this->concern(['message' => 'ComLab 3 is upstairs.']);
        Concern::factory()->create(['resolved_at' => now()]);
        $this->admin->unreadNotifications()->where('data->concern_id', $older->id)->update(['read_at' => now()]);
        Sanctum::actingAs($this->admin);

        $this->getJson(route('api.admin.concerns.index'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$newer->id, $older->id])
            ->assertJsonPath('data.*.unread', [true, false])
            ->assertJsonPath('data.0.sender', 'Juan Luna')
            ->assertJsonPath('meta.open_count', 2)
            ->assertJsonPath('meta.resolved_count', 1)
            ->assertJsonPath('meta.unread_count', 1);

        $this->getJson(route('api.admin.concerns.index', ['status' => 'resolved']))->assertJsonCount(1, 'data')->assertJsonPath('meta.status', 'resolved');
    }

    public function test_opening_a_concern_marks_it_read_for_this_admin(): void
    {
        $concern = $this->concern();
        Sanctum::actingAs($this->admin);

        $this->getJson(route('api.admin.concerns.show', $concern))
            ->assertOk()
            ->assertJsonPath('data.message', 'The library is missing.')
            ->assertJsonPath('data.account.role', 'Student');
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
    }

    public function test_a_reply_is_emailed_shown_in_the_portal_and_resolves(): void
    {
        $concern = $this->concern();
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin->notify(new ConcernReceived($concern));
        Sanctum::actingAs($this->admin);

        $this->postJson(route('api.admin.concerns.reply', $concern), ['message' => '  We added it.  ', 'resolve' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Reply sent to juan@tcc.edu.ph. It also shows in their portal.')
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.replies.0.message', 'We added it.')
            ->assertJsonPath('data.replies.0.by', 'Ada Admin');

        Mail::assertQueued(ConcernReplyMail::class, fn (ConcernReplyMail $mail) => $mail->hasTo('juan@tcc.edu.ph'));
        $this->assertSame(1, $this->student->notifications()->where('type', ConcernReplied::class)->count());
        $this->assertSame(0, $otherAdmin->unreadNotifications()->count());
    }

    public function test_a_reply_needs_a_message(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(route('api.admin.concerns.reply', $this->concern()), ['message' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'Write your reply first.']);
        Mail::assertNothingQueued();
    }

    public function test_resolve_and_reopen(): void
    {
        $concern = $this->concern();
        Sanctum::actingAs($this->admin);

        $this->patchJson(route('api.admin.concerns.resolve', $concern))->assertOk()->assertJsonPath('message', 'Marked as resolved.')->assertJsonPath('data.status', 'resolved');
        $this->assertSame(0, $this->admin->unreadNotifications()->count());

        $this->patchJson(route('api.admin.concerns.reopen', $concern))->assertOk()->assertJsonPath('message', 'Moved back to open.')->assertJsonPath('data.status', 'open');
        $this->assertNull($concern->fresh()->resolved_at);
    }
}
