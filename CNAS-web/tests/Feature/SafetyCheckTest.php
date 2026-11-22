<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\SafetyStatus;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "I'm safe" during an alert: signed-in users only, one answer each, deleted 30 days after the alert ends.
 */
class SafetyCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_marks_themselves_safe(): void
    {
        $alert = Alert::factory()->create();
        $student = User::factory()->create();
        $this->freezeSecond();

        $this->actingAs($student)->postJson(route('alerts.safe', $alert))
            ->assertOk()
            ->assertExactJson(['status' => 'safe']);
        // Tapping again doesn't add a second answer.
        $this->actingAs($student)->postJson(route('alerts.safe', $alert))->assertOk();

        $check = SafetyCheck::sole();
        $this->assertTrue($check->alert->is($alert));
        $this->assertTrue($check->user->is($student));
        $this->assertSame(SafetyStatus::Safe, $check->status);
        $this->assertEquals(now(), $check->responded_at);
    }

    public function test_without_javascript_the_form_returns_to_the_page_with_a_message(): void
    {
        $alert = Alert::factory()->create();

        $this->actingAs(User::factory()->create())->from(route('student.dashboard'))
            ->post(route('alerts.safe', $alert))
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('status', 'Thanks. The administrators can see that you are safe.');
    }

    public function test_guests_cannot_answer(): void
    {
        $alert = Alert::factory()->create();

        $this->post(route('alerts.safe', $alert))->assertRedirect(route('login'));

        $this->assertDatabaseEmpty('safety_checks');
    }

    public function test_a_resolved_alert_takes_no_answers(): void
    {
        $alert = Alert::factory()->resolved()->create();

        $this->actingAs(User::factory()->create())->postJson(route('alerts.safe', $alert))
            ->assertConflict()
            ->assertExactJson(['message' => 'This alert is over.']);

        $this->assertDatabaseEmpty('safety_checks');
    }

    public function test_the_app_marks_a_user_safe_with_a_token(): void
    {
        $alert = Alert::factory()->create();
        // Unverified users can answer, like on the web.
        $student = User::factory()->unverified()->create();
        Sanctum::actingAs($student);

        $this->postJson(route('api.alerts.safe', $alert))
            ->assertOk()
            ->assertExactJson(['status' => 'safe']);

        $this->assertTrue(SafetyCheck::sole()->user->is($student));
    }

    public function test_the_app_needs_a_token(): void
    {
        $this->postJson(route('api.alerts.safe', Alert::factory()->create()))->assertUnauthorized();

        $this->assertDatabaseEmpty('safety_checks');
    }

    public function test_the_app_gets_409_for_an_alert_that_is_over(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.alerts.safe', Alert::factory()->resolved()->create()))
            ->assertConflict()
            ->assertExactJson(['message' => 'This alert is over.']);

        $this->assertDatabaseEmpty('safety_checks');
    }

    public function test_the_app_gets_409_for_an_alert_that_is_not_an_emergency(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.alerts.safe', Alert::factory()->warning()->create()))
            ->assertConflict()
            ->assertExactJson(['message' => 'Only an emergency asks whether you are safe.']);
    }

    public function test_the_app_answer_is_rate_limited(): void
    {
        $alert = Alert::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('api.alerts.safe', $alert))->assertOk();
        }

        $this->postJson(route('api.alerts.safe', $alert))->assertTooManyRequests();
    }

    public function test_the_app_sees_an_answer_given_on_another_device(): void
    {
        $alert = Alert::factory()->create();
        $student = User::factory()->create();
        $token = $student->createToken('Pixel')->plainTextToken;

        $this->withToken($token)->getJson(route('api.alerts.active'))->assertJsonPath('data.my_safety_status', null);

        SafetyCheck::markSafe($alert, $student); // "I'm safe" on the website or another phone.

        $this->withToken($token)->getJson(route('api.alerts.active'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $alert->id)
            ->assertJsonPath('data.my_safety_status', 'safe');
    }

    public function test_guests_never_get_a_safety_status(): void
    {
        $alert = Alert::factory()->create();
        SafetyCheck::markSafe($alert, User::factory()->create());

        $this->getJson(route('api.alerts.active'))->assertJsonMissingPath('data.my_safety_status');
        // A bad token answers like a guest: nothing about anyone's answer.
        $this->withToken('1|not-a-real-token')->getJson(route('api.alerts.active'))->assertJsonPath('data.my_safety_status', null);
    }

    public function test_the_safety_status_is_only_looked_up_during_an_active_emergency(): void
    {
        $student = User::factory()->create();
        $token = $student->createToken('Pixel')->plainTextToken;
        $alert = Alert::factory()->warning()->create();
        SafetyCheck::markSafe($alert, $student);

        $this->withToken($token)->getJson(route('api.alerts.active'))->assertJsonPath('data.my_safety_status', null);

        $alert->forceFill(['status' => AlertStatus::Resolved, 'resolved_at' => now(), 'severity' => AlertSeverity::Emergency])->save();
        $this->withToken($token)->getJson(route('api.alerts.active'))
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.my_safety_status', null);
    }

    public function test_answers_are_deleted_thirty_days_after_the_alert_ends(): void
    {
        $old = Alert::factory()->resolved()->create(['resolved_at' => now()->subDays(31)]);
        $recent = Alert::factory()->resolved()->create(['resolved_at' => now()->subDays(29)]);
        $active = Alert::factory()->create();
        foreach ([$old, $recent, $active] as $alert) {
            SafetyCheck::markSafe($alert, User::factory()->create());
        }

        $this->artisan('model:prune', ['--model' => [SafetyCheck::class]])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recent->id, $active->id], SafetyCheck::pluck('alert_id')->all());
    }
}
