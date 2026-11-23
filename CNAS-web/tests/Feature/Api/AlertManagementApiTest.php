<?php

namespace Tests\Feature\Api;

use App\Enums\AlertLogAction;
use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Jobs\FanOutExpoAlertPush;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Sending and ending alerts from the mobile app (/api/alerts): the admin and trusted faculty and staff, with the web's
 * requests and Alert methods, so every change is logged and pushed like on the web (Admin/AlertTest).
 */
class AlertManagementApiTest extends TestCase
{
    use RefreshDatabase;

    /** Inside the NEE Building (b1). */
    private const NEE_LATITUDE = 8.5409326;

    private const NEE_LONGITUDE = 124.7483871;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CampusMapSeeder::class);
        Queue::fake([SendAlertPush::class, FanOutExpoAlertPush::class]);
    }

    private function admin(): User
    {
        return User::factory()->create(['first_name' => 'Campus', 'last_name' => 'Admin', 'role' => UserRole::Admin]);
    }

    private function trusted(UserRole $role = UserRole::Staff): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->trustForAlerts(true);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function alertInput(array $overrides = []): array
    {
        return [
            'type' => 'fire',
            'severity' => 'emergency',
            'title' => 'Fire on campus',
            'message' => 'A fire has been reported at the NEE Building.',
            'instructions' => 'Leave the building now.',
            'is_drill' => true,
            'latitude' => self::NEE_LATITUDE,
            'longitude' => self::NEE_LONGITUDE,
            'danger_radius_m' => 50,
            'building_codes' => ['b1'],
            ...$overrides,
        ];
    }

    private function activeAlert(User $by): Alert
    {
        return Alert::send(['type' => AlertType::Fire, ...AlertType::Fire->template(), 'severity' => AlertSeverity::Emergency], $by);
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson(route('api.alerts.index'))->assertUnauthorized();
        $this->postJson(route('api.alerts.store'), $this->alertInput())->assertUnauthorized();
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    public function test_students_and_untrusted_faculty_and_staff_get_403(UserRole $role): void
    {
        $alert = $this->activeAlert($this->admin());
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson(route('api.alerts.index'))->assertForbidden();
        $this->getJson(route('api.alerts.form'))->assertForbidden();
        $this->postJson(route('api.alerts.store'), $this->alertInput())->assertForbidden();
        $this->postJson(route('api.alerts.resolve', $alert), ['resolution' => 'all_clear', 'resolved_message' => 'Over.'])->assertForbidden();
        $this->getJson(route('api.alerts.safety', $alert))->assertForbidden();
        $this->assertTrue($alert->fresh()->isActive());
    }

    public function test_the_form_has_each_types_level_and_wording_the_levels_and_the_buildings(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->getJson(route('api.alerts.form'))->assertOk()
            ->assertJsonPath('types.0.value', 'fire')
            ->assertJsonPath('types.0.severity', 'emergency')
            ->assertJsonPath('types.0.template.title', 'Fire on campus')
            ->assertJsonPath('radii', [30, 50, 100])
            ->assertJsonPath('default_radius', 50);

        $this->assertContains(['code' => 'b1', 'name' => 'NEE Building'], $response->json('buildings'));
        $this->assertSame(['advisory', 'warning', 'emergency'], $response->json('severities.*.value'));
        $this->assertSame(['all_clear', 'false_alarm'], $response->json('resolutions.*.value'));
    }

    public function test_the_admin_sends_a_drill_with_a_pin_and_it_is_logged_and_pushed(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson(route('api.alerts.store'), $this->alertInput())
            ->assertCreated()
            ->assertJsonPath('message', 'Drill alert sent. It shows on every page within 15 seconds and is pushed to subscribed devices.')
            ->assertJsonPath('data.is_drill', true)
            ->assertJsonPath('data.location', 'NEE Building')
            ->assertJsonPath('data.danger_radius_m', 50)
            ->assertJsonPath('data.sent_by', 'Campus Admin');

        $alert = Alert::sole();
        $this->assertSame([AlertLogAction::Sent], $alert->logs()->pluck('action')->all());
        Queue::assertPushed(SendAlertPush::class, fn (SendAlertPush $job) => $job->alert->is($alert));
        Queue::assertPushed(FanOutExpoAlertPush::class);
    }

    public function test_a_second_alert_is_refused_while_one_is_active(): void
    {
        $admin = $this->admin();
        $this->activeAlert($admin);
        Sanctum::actingAs($admin);

        $this->postJson(route('api.alerts.store'), $this->alertInput())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['form' => 'An alert is already active. Edit or resolve it first.']);
        $this->assertSame(1, Alert::count());
    }

    public function test_the_web_rules_apply_to_the_pin_and_the_wording(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson(route('api.alerts.store'), $this->alertInput(['latitude' => 14.5995, 'longitude' => 120.9842, 'title' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude' => 'Put the pin on the campus map.', 'title' => 'Give the alert a short title.']);
        $this->postJson(route('api.alerts.store'), $this->alertInput(['danger_radius_m' => 75]))
            ->assertJsonValidationErrors(['danger_radius_m' => 'Choose a danger zone of 30, 50 or 100 m.']);
        $this->assertDatabaseEmpty('alerts');
    }

    public function test_the_admin_edits_the_wording_and_level_and_both_are_logged(): void
    {
        $admin = $this->admin();
        $alert = $this->activeAlert($admin);
        Sanctum::actingAs($admin);

        $this->putJson(route('api.alerts.update', $alert), ['title' => 'Fire at the NEE Building', 'message' => 'Smoke on the 2nd floor.', 'instructions' => null, 'severity' => 'warning'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Fire at the NEE Building')
            ->assertJsonPath('data.severity', 'warning');

        $this->assertSame(
            [['sent', null], ['edited', null], ['severity', 'Emergency → Warning']],
            $alert->logs()->orderBy('id')->get()->map(fn ($log) => [$log->action->value, $log->detail])->all(),
        );
        Queue::assertPushed(SendAlertPush::class, 2);
    }

    public function test_trusted_staff_send_and_end_alerts_but_only_the_admin_edits_them(): void
    {
        $staff = $this->trusted();
        Sanctum::actingAs($staff);

        $this->postJson(route('api.alerts.store'), $this->alertInput(['is_drill' => false]))
            ->assertCreated()
            ->assertJsonPath('message', 'Alert sent. Everyone sees it within 15 seconds, and the administrator can see you sent it.');
        $alert = Alert::sole();

        $this->putJson(route('api.alerts.update', $alert), ['title' => 'Changed', 'message' => 'Changed.', 'severity' => 'advisory'])->assertForbidden();
        $this->getJson(route('api.alerts.safety', $alert))->assertForbidden();
        $this->getJson(route('api.alerts.index'))->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('safety', null);

        $this->postJson(route('api.alerts.resolve', $alert), ['resolution' => 'false_alarm', 'resolved_message' => 'False alarm.'])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'false_alarm');
        $this->assertSame('Fire on campus', $alert->fresh()->title);
        $this->assertSame($staff->id, $alert->fresh()->resolved_by);
    }

    public function test_resolving_ends_the_alert_once(): void
    {
        $admin = $this->admin();
        $alert = $this->activeAlert($admin);
        Sanctum::actingAs($admin);

        $this->postJson(route('api.alerts.resolve', $alert), ['resolution' => 'all_clear', 'resolved_message' => 'All clear.'])
            ->assertOk()
            ->assertJsonPath('message', 'Marked as All clear. Everyone sees it for the next 15 minutes.');
        $this->postJson(route('api.alerts.resolve', $alert), ['resolution' => 'all_clear', 'resolved_message' => 'Again.'])->assertConflict();
        $this->putJson(route('api.alerts.update', $alert), ['title' => 'x', 'message' => 'y', 'severity' => 'emergency'])->assertConflict();

        $this->assertSame(AlertResolution::AllClear, $alert->fresh()->resolution);
        Queue::assertPushed(SendAlertPush::class, fn (SendAlertPush $job) => $job->resolution === AlertResolution::AllClear);
    }

    public function test_the_admin_list_has_the_active_alert_its_safe_count_and_the_logged_history(): void
    {
        $admin = $this->admin();
        $alert = $this->activeAlert($admin);
        SafetyCheck::markSafe($alert, User::factory()->create(['role' => UserRole::Student]));
        Sanctum::actingAs($admin);

        $this->getJson(route('api.alerts.index'))
            ->assertOk()
            ->assertJsonPath('active.id', $alert->id)
            ->assertJsonPath('safety.safe', 1)
            ->assertJsonPath('history.0.logs.0.action', 'sent')
            ->assertJsonPath('history.0.logs.0.by', 'Campus Admin')
            ->assertJsonPath('can_edit', true);
    }

    public function test_the_live_view_counts_by_college_and_lists_who_is_safe(): void
    {
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        $admin = $this->admin();
        $alert = $this->activeAlert($admin);
        $itStudent = User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT', 'first_name' => 'Ana', 'last_name' => 'Cruz']);
        User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT']);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'program_code' => null, 'first_name' => 'Ben', 'last_name' => 'Reyes']);
        SafetyCheck::markSafe($alert, $itStudent);
        SafetyCheck::markSafe($alert, $staff);
        SafetyCheck::markSafe($alert, $admin); // Admins aren't counted.
        Sanctum::actingAs($admin);

        $response = $this->getJson(route('api.alerts.safety', $alert))
            ->assertOk()
            ->assertJsonPath('safe', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('not_answered', 1);
        $it = collect($response->json('colleges'))->firstWhere('id', $itStudent->program->college_id);
        $this->assertSame(['safe' => 1, 'total' => 2], ['safe' => $it['safe'], 'total' => $it['total']]);
        $this->assertSame('No college', collect($response->json('colleges'))->last()['name']);

        $this->getJson(route('api.alerts.safety', [$alert, 'college' => $itStudent->program->college_id]))
            ->assertJsonPath('people.*.name', ['Ana Cruz']);
        $this->getJson(route('api.alerts.safety', [$alert, 'college' => 'none']))
            ->assertJsonPath('people.*.name', ['Ben Reyes']);
    }

    public function test_the_app_knows_who_can_send_alerts(): void
    {
        Sanctum::actingAs($this->trusted(UserRole::Faculty));
        $this->getJson(route('api.me'))->assertJsonPath('can_send_alerts', true);

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Student]));
        $this->getJson(route('api.me'))->assertJsonPath('can_send_alerts', false);
    }
}
