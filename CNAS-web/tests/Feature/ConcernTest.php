<?php

namespace Tests\Feature;

use App\Enums\ConcernTopic;
use App\Enums\UserRole;
use App\Models\Concern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sending a concern, suggestion or feedback: from the portal's Help & contact page (signed in) or the site footer (guest).
 * The admin inbox is Tests\Feature\Admin\ConcernTest.
 */
class ConcernTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_sends_a_concern_and_the_reply_goes_to_their_account_email(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'email' => 'juan@tcc.edu.ph']);

        $this->actingAs($student)
            ->from(route('student.help'))
            ->post(route('concerns.store'), [
                'topic' => 'account',
                'message' => 'I cannot sign in on my phone.',
                'email' => 'someone-else@example.com',
            ])
            ->assertRedirect(route('student.help'))
            ->assertSessionHas('concern_status', 'Sent. The administrator will reply to juan@tcc.edu.ph.');

        $concern = Concern::sole();
        $this->assertTrue($concern->user->is($student));
        $this->assertSame('juan@tcc.edu.ph', $concern->email);
        $this->assertSame(ConcernTopic::Account, $concern->topic);
        $this->assertSame('I cannot sign in on my phone.', $concern->message);
        $this->assertNull($concern->resolved_at);
    }

    public function test_a_guest_sends_feedback_from_the_site_footer_with_their_email(): void
    {
        $this->from(route('landing'))
            ->post(route('concerns.store'), ['topic' => 'suggestion', 'message' => 'Please add the library hours.', 'email' => ' Ana@Example.com '])
            ->assertRedirect(route('landing').'#feedback')
            ->assertSessionHas('concern_status', 'Sent. The administrator will reply to ana@example.com.');

        $concern = Concern::sole();
        $this->assertNull($concern->user_id);
        $this->assertSame('ana@example.com', $concern->email);
    }

    public function test_an_empty_form_is_refused_with_a_message_for_each_field(): void
    {
        $this->from(route('landing'))
            ->post(route('concerns.store'), [])
            ->assertRedirect(route('landing').'#feedback')
            ->assertSessionHasErrors([
                'topic' => 'Choose what your message is about.',
                'message' => 'Write your message.',
                'email' => 'Enter your email so we can reply.',
            ], errorBag: 'concern');

        $this->assertDatabaseCount('concerns', 0);
    }

    public function test_an_unknown_topic_and_a_too_short_message_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->post(route('concerns.store'), ['topic' => 'refund', 'message' => 'help'])
            ->assertSessionHasErrors([
                'topic' => 'Choose what your message is about.',
                'message' => 'Tell us a little more, at least 10 characters.',
            ], errorBag: 'concern');

        $this->assertDatabaseCount('concerns', 0);
    }

    public function test_sending_is_limited_to_five_in_ten_minutes(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $concern = ['topic' => 'other', 'message' => 'Just checking that this works.'];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($student)->post(route('concerns.store'), $concern)->assertRedirect();
        }
        $this->actingAs($student)->post(route('concerns.store'), $concern)->assertTooManyRequests();

        $this->assertDatabaseCount('concerns', 5);
    }
}
