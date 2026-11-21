<?php

namespace Tests\Feature\Portal;

use App\Enums\AlertStatus;
use App\Enums\UserRole;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Faculty and staff the admin trusts send and end emergency alerts from the faculty portal (Portal\AlertController).
 */
class TrustedAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(array $overrides = []): array
    {
        return [
            'type' => 'fire',
            'severity' => 'emergency',
            'title' => 'Fire in the NEE Building',
            'message' => 'Smoke on the second floor.',
            'instructions' => 'Leave by the nearest stairs.',
            ...$overrides,
        ];
    }

    #[TestWith([UserRole::Staff])]
    #[TestWith([UserRole::Faculty])]
    public function test_a_trusted_faculty_or_staff_member_sends_an_alert_that_goes_out_at_once(UserRole $role): void
    {
        $guard = User::factory()->trustedForAlerts()->create(['role' => $role]);

        $this->actingAs($guard)
            ->post(route('faculty.alerts.store'), $this->alert())
            ->assertRedirect(route('faculty.alerts.index'))
            ->assertSessionHas('status', 'Alert sent. Everyone sees it within 15 seconds, and the administrator can see you sent it.');

        $alert = Alert::sole();
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertTrue($alert->creator->is($guard));
        Queue::assertPushed(SendAlertPush::class);
    }

    public function test_a_trusted_user_ends_the_active_alert(): void
    {
        $guard = User::factory()->trustedForAlerts()->create(['role' => UserRole::Staff]);
        $alert = Alert::send($this->alert(), User::factory()->create(['role' => UserRole::Admin]));

        $this->actingAs($guard)
            ->post(route('faculty.alerts.resolve', $alert), ['resolution' => 'all_clear', 'resolved_message' => 'The fire is out.'])
            ->assertRedirect(route('faculty.alerts.index'))
            ->assertSessionHas('status', 'Marked as All clear. Everyone gets a notification.');

        $this->assertFalse($alert->fresh()->isActive());
        $this->assertTrue($alert->fresh()->resolver->is($guard));
    }

    #[TestWith(['get', 'faculty.alerts.index'])]
    #[TestWith(['get', 'faculty.alerts.create'])]
    #[TestWith(['post', 'faculty.alerts.store'])]
    public function test_faculty_the_admin_has_not_trusted_are_forbidden(string $method, string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))
            ->{$method}(route($route), $this->alert())
            ->assertForbidden();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_a_student_marked_trusted_still_cannot_send(): void
    {
        $this->actingAs(User::factory()->trustedForAlerts()->create(['role' => UserRole::Student]))
            ->post(route('faculty.alerts.store'), $this->alert())
            ->assertForbidden();
    }

    public function test_turning_trust_off_takes_effect_on_the_next_request(): void
    {
        $guard = User::factory()->trustedForAlerts()->create(['role' => UserRole::Staff]);
        $this->actingAs($guard)->get(route('faculty.alerts.index'))->assertOk();

        $guard->trustForAlerts(false);

        $this->actingAs($guard)->get(route('faculty.alerts.index'))->assertForbidden();
    }

    public function test_only_trusted_users_see_emergency_alerts_in_the_sidebar(): void
    {
        $this->actingAs(User::factory()->trustedForAlerts()->create(['role' => UserRole::Staff]))
            ->get(route('faculty.dashboard'))
            ->assertSee(route('faculty.alerts.index'), false);

        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))
            ->get(route('faculty.dashboard'))
            ->assertDontSee(route('faculty.alerts.index'), false);
    }

    public function test_the_page_offers_to_send_when_nothing_is_active_and_to_end_the_active_alert(): void
    {
        $guard = User::factory()->trustedForAlerts()->create(['role' => UserRole::Staff]);

        $this->actingAs($guard)->get(route('faculty.alerts.index'))
            ->assertSee('No alert is active')
            ->assertSee('href="'.route('faculty.alerts.create').'"', false);

        $alert = Alert::send($this->alert(), $guard);

        $this->actingAs($guard)->get(route('faculty.alerts.index'))
            ->assertSeeInOrder(['Active now: Fire', 'Fire in the NEE Building', 'action="'.route('faculty.alerts.resolve', $alert).'"'], false);
        $this->actingAs($guard)->get(route('faculty.alerts.create'))
            ->assertRedirect(route('faculty.alerts.index'));
    }

    public function test_the_send_page_reuses_the_admin_form(): void
    {
        $this->actingAs(User::factory()->trustedForAlerts()->create(['role' => UserRole::Faculty]))
            ->get(route('faculty.alerts.create'))
            ->assertOk()
            ->assertSee('action="'.route('faculty.alerts.store').'"', false)
            ->assertSee('data-alert-picker', false)
            ->assertSee('Send alert');
    }
}
