<?php

namespace Tests\Feature\Admin;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\LoginChannel;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\Concern;
use App\Models\LoginActivity;
use App\Models\SafetyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The live "I'm safe" panel on Admin > Alerts (admin.alerts.safety) and the dashboard numbers (AdminAnalytics, the web
 * overview and GET /api/admin/dashboard).
 */
class AdminLiveDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function emergency(User $admin): Alert
    {
        Queue::fake();

        return Alert::send(['type' => AlertType::Fire, ...AlertType::Fire->template(), 'severity' => AlertSeverity::Emergency, 'is_drill' => true], $admin);
    }

    public function test_the_alerts_page_keeps_the_safety_panel_live(): void
    {
        $admin = $this->admin();
        $alert = $this->emergency($admin);

        $this->actingAs($admin)->get(route('admin.alerts.index'))
            ->assertSee('data-safety-live="'.route('admin.alerts.safety', $alert).'"', false)
            ->assertSee('Updates by itself');

        SafetyCheck::markNeedHelp($alert, User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Ana', 'last_name' => 'Cruz']), 'Gym');

        $this->actingAs($admin)->get(route('admin.alerts.safety', $alert))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeInOrder(['need help', 'Needs help', 'Ana Cruz', 'Gym'])
            ->assertDontSee('<html', false);
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Staff])]
    public function test_only_the_admin_gets_the_panel_or_the_dashboard_numbers(UserRole $role): void
    {
        $alert = $this->emergency($this->admin());
        $user = User::factory()->create(['role' => $role]);
        $user->trustForAlerts(true);

        $this->actingAs($user)->get(route('admin.alerts.safety', $alert))->assertForbidden();
        Sanctum::actingAs($user);
        $this->getJson(route('api.admin.dashboard'))->assertForbidden();
    }

    public function test_the_dashboard_numbers_count_sign_ins_accounts_and_concerns(): void
    {
        $this->travelTo('2026-11-23 04:00:00'); // Monday noon, campus time.
        $admin = $this->admin();
        $student = User::factory()->create(['role' => UserRole::Student]);
        LoginActivity::factory()->for($student)->create(['channel' => LoginChannel::Api, 'logged_in_at' => now()]);
        LoginActivity::factory()->for($student)->create(['channel' => LoginChannel::Web, 'logged_in_at' => now()->subDays(2)]);
        LoginActivity::factory()->for($admin)->create(['channel' => LoginChannel::Web, 'logged_in_at' => now()->subDays(9)]);
        Concern::factory()->create(['topic' => 'map']);
        Concern::factory()->create(['topic' => 'map', 'resolved_at' => now()]);
        Sanctum::actingAs($admin);

        $response = $this->getJson(route('api.admin.dashboard'))
            ->assertOk()
            ->assertJsonPath('accounts.total', 2)
            ->assertJsonPath('accounts.by_role.student', 1)
            ->assertJsonPath('sign_ins.last_7', 2)
            ->assertJsonPath('sign_ins.previous_7', 1)
            ->assertJsonPath('sign_ins.people_7', 1)
            ->assertJsonPath('concerns.open', 1)
            ->assertJsonPath('concerns.by_topic.0', ['topic' => 'map', 'label' => 'Map', 'count' => 2])
            ->assertJsonPath('term_warning.kind', 'closed');

        $days = $response->json('sign_ins.days');
        $this->assertCount(14, $days);
        $this->assertSame(['date' => '2026-11-23', 'web' => 0, 'app' => 1], end($days));

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Sign-ins, last 14 days')
            ->assertSee('data-chart="sign-ins"', false);
    }
}
