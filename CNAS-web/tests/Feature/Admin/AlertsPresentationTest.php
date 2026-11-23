<?php

namespace Tests\Feature\Admin;

use App\Enums\AlertLogAction;
use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlertsPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_lists_each_alert_on_one_line_linking_to_its_report(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Campus', 'last_name' => 'Admin']);
        $alert = Alert::factory()->drill()->resolved('Practice complete.', AlertResolution::FalseAlarm)->create([
            'created_by' => $admin->id,
            'title' => 'Scheduled drill',
        ]);

        $this->actingAs($admin)->get(route('admin.alerts.index'))
            ->assertOk()
            ->assertSee('No active alert')
            ->assertSeeInOrder(['History', 'Scheduled drill', 'Drill', 'Emergency', 'False alarm'])
            ->assertSee(route('admin.alerts.show', $alert), false)
            ->assertSee('1 alert sent')
            ->assertDontSee('data-resolve-form', false)
            ->assertSee(route('admin.alerts.create'), false);
        Queue::assertNothingPushed();
    }

    public function test_the_report_shows_the_original_wording_escaped_and_the_activity(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Campus', 'last_name' => 'Admin']);
        $alert = Alert::factory()->drill()->resolved('Practice complete.', AlertResolution::FalseAlarm)->create([
            'created_by' => $admin->id,
            'title' => 'Scheduled drill',
            'message' => '<script>alert("test")</script> Original message.',
            'what_to_do' => 'Use the nearest safe exit.',
            'instructions' => "First line.\nSecond line.",
        ]);
        $alert->logs()->create(['action' => AlertLogAction::Sent, 'user_id' => $admin->id, 'created_at' => now()]);

        $this->actingAs($admin)->get(route('admin.alerts.show', $alert))
            ->assertOk()
            ->assertSeeInOrder(['Scheduled drill', 'Sent', 'by Campus Admin', 'Ended', 'Lasted'])
            ->assertSee('Use the nearest safe exit.')
            ->assertSee($alert->message)
            ->assertDontSee($alert->message, false)
            ->assertSee($alert->instructions)
            ->assertSee('False alarm message')
            ->assertSee('Practice complete.')
            ->assertSeeInOrder(['Activity', 'Sent', 'Campus Admin']);
        $this->assertSame($alert->message, $alert->fresh()->message);
    }

    public function test_the_report_lists_each_help_request_and_who_helped(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Rita', 'last_name' => 'Reyes']);
        $alert = Alert::factory()->create(['created_by' => $admin->id]);
        $person = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $check = SafetyCheck::markNeedHelp($alert, $person, 'Stuck on the 2nd floor', ['latitude' => 8.5, 'longitude' => 124.7, 'accuracy' => 12.0]);
        $check->respond($admin);
        $check->markHelped($admin);
        $alert->resolve('All clear.', $admin);

        $this->actingAs($admin)->get(route('admin.alerts.show', $alert))
            ->assertOk()
            ->assertSee('1 needed help')
            ->assertSeeInOrder(['Help requests', 'Ana Cruz', 'Helped in', '“Stuck on the 2nd floor”', 'asked for help', 'Rita Reyes responded', 'marked helped by Rita Reyes'])
            ->assertSee('data-help-points', false)
            ->assertSeeInOrder(['Activity', 'Responding to Ana Cruz', 'Helped Ana Cruz']);
    }

    public function test_the_report_says_when_answers_were_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $alert = Alert::factory()->resolved()->create(['created_by' => $admin->id, 'resolved_at' => now()->subDays(SafetyCheck::RETENTION_DAYS + 1)]);

        $this->actingAs($admin)->get(route('admin.alerts.show', $alert))
            ->assertOk()
            ->assertSee('Deleted. Answers are kept for 30 days after an alert ends.')
            ->assertDontSee('data-help-workspace', false);
    }

    public function test_active_wording_and_safeguards_remain_available(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $alert = Alert::factory()->warning()->create(['created_by' => $admin->id, 'what_to_do' => 'Move to higher ground.']);

        $this->actingAs($admin)->get(route('admin.alerts.index'))
            ->assertOk()
            ->assertSee('Active now: Flood')
            ->assertSee('Move to higher ground.')
            ->assertSee('End the active alert before sending another.')
            ->assertSee(route('admin.alerts.edit', $alert), false)
            ->assertSee(route('admin.alerts.resolve', $alert), false)
            ->assertSee('data-safety-live', false)
            ->assertSee('data-submit-once', false)
            ->assertSee('End this alert? Everyone gets a notification.')
            // A warning doesn't ask "I need help", so there is no help map.
            ->assertDontSee('data-help-workspace', false)
            ->assertDontSee(route('admin.alerts.create'), false);
        Queue::assertNothingPushed();
    }

    public function test_history_keeps_pagination_and_does_not_lose_older_records(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Alert::factory()->count(25)->resolved()->create(['created_by' => $admin->id, 'sent_at' => now()]);
        Alert::factory()->resolved()->create(['created_by' => $admin->id, 'title' => 'Older archived advisory', 'sent_at' => now()->subDay()]);

        $this->actingAs($admin)->get(route('admin.alerts.index'))
            ->assertOk()->assertDontSee('Older archived advisory')->assertSee('page=2', false);
        $this->get(route('admin.alerts.index', ['page' => 2]))
            ->assertOk()->assertSee('Older archived advisory');
        Queue::assertNothingPushed();
    }

    public function test_history_filters_by_search_type_level_drill_and_month_and_keeps_them_across_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->travelTo('2026-10-15 04:00:00');
        Alert::factory()->resolved()->create(['created_by' => $admin->id, 'title' => 'October flood', 'type' => AlertType::Flood, 'severity' => AlertSeverity::Warning, 'is_drill' => false, 'sent_at' => now()]);
        $this->travelTo('2026-11-10 04:00:00');
        Alert::factory()->drill()->resolved()->create(['created_by' => $admin->id, 'title' => 'November fire drill', 'type' => AlertType::Fire, 'sent_at' => now()]);
        Alert::factory()->resolved()->create(['created_by' => $admin->id, 'title' => 'Gas leak near canteen', 'type' => AlertType::Other, 'severity' => AlertSeverity::Advisory, 'is_drill' => false, 'sent_at' => now()]);

        // Past the "All clear" banner, whose alert data is on every page.
        $this->travel(1)->hour();
        $index = fn (array $filters) => $this->actingAs($admin)->get(route('admin.alerts.index', $filters))->assertOk();

        $index(['q' => 'canteen'])->assertSee('Gas leak near canteen')->assertDontSee('October flood')->assertDontSee('November fire drill')->assertSee('1 alert match the filters');
        $index(['type' => 'flood'])->assertSee('October flood')->assertDontSee('Gas leak near canteen');
        $index(['severity' => 'advisory'])->assertSee('Gas leak near canteen')->assertDontSee('October flood');
        $index(['kind' => 'drill'])->assertSee('November fire drill')->assertDontSee('Gas leak near canteen');
        $index(['kind' => 'real'])->assertDontSee('November fire drill')->assertSee('October flood');
        $index(['month' => '2026-10'])->assertSee('October flood')->assertDontSee('Gas leak near canteen');
        // Nonsense is ignored rather than failing.
        $index(['type' => 'meteor', 'month' => '2026-13', 'kind' => 'maybe'])->assertSee('October flood')->assertSee('Gas leak near canteen');

        Alert::factory()->count(25)->resolved()->create(['created_by' => $admin->id, 'type' => AlertType::Flood, 'sent_at' => now()]);
        $index(['type' => 'flood'])->assertSee('type=flood&amp;page=2', false);
    }
}
