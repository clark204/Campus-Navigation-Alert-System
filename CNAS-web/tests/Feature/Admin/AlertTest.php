<?php

namespace Tests\Feature\Admin;

use App\Enums\AlertLogAction;
use App\Enums\AlertResolution;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Admins send (with an optional incident pin, danger zone and buildings), edit and resolve emergency alerts; every
 * action is logged and sending or resolving queues a push to subscribed devices.
 */
class AlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These alerts name real buildings (b1 NEE Building, b8 Library Building) and pin inside the real campus.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CampusMapSeeder::class);
    }

    /** Inside the NEE Building (b1). */
    private const NEE_LATITUDE = 8.5409326;

    private const NEE_LONGITUDE = 124.7483871;

    private function admin(): User
    {
        return User::factory()->create(['first_name' => 'Campus', 'last_name' => 'Admin', 'role' => UserRole::Admin]);
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
            'latitude' => (string) self::NEE_LATITUDE,
            'longitude' => (string) self::NEE_LONGITUDE,
            'danger_radius_m' => '50',
            'building_codes' => ['b1'],
            ...$overrides,
        ];
    }

    public function test_the_send_form_starts_with_the_chosen_type_its_wording_and_the_campus_buildings(): void
    {
        $this->actingAs($this->admin())->get(route('admin.alerts.create', ['type' => 'flood']))
            ->assertOk()
            ->assertSee('<input type="radio" name="type" value="flood" checked', false)
            ->assertSee('value="Flooding on campus"', false)
            ->assertSee('Move to an upper floor or higher ground.')
            ->assertSee('value="b1"', false)
            ->assertSee('NEE Building')
            ->assertSee('<input type="radio" name="danger_radius_m" value="50" checked', false);
    }

    public function test_an_admin_sends_an_alert_with_a_pin_danger_zone_and_buildings_and_it_is_pushed(): void
    {
        Queue::fake([SendAlertPush::class]);
        $admin = $this->admin();
        $this->freezeSecond();

        $this->actingAs($admin)->post(route('admin.alerts.store'), $this->alertInput(['is_drill' => '1', 'building_codes' => ['b1', 'b8']]))
            ->assertRedirect(route('admin.alerts.index'))
            ->assertSessionHas('status', 'Drill alert sent. It shows on every page within 15 seconds and is pushed to subscribed devices.');

        $alert = Alert::sole();
        $this->assertSame(AlertType::Fire, $alert->type);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertTrue($alert->is_drill);
        $this->assertSame(self::NEE_LATITUDE, $alert->latitude);
        $this->assertSame(self::NEE_LONGITUDE, $alert->longitude);
        $this->assertSame(50, $alert->danger_radius_m);
        $this->assertSame(['b1', 'b8'], $alert->building_codes);
        $this->assertSame('NEE Building and Library Building', $alert->locationLabel());
        $this->assertTrue($alert->creator->is($admin));
        $this->assertEquals(now(), $alert->sent_at);
        $this->assertSame([AlertLogAction::Sent], $alert->logs->pluck('action')->all());
        Queue::assertPushed(SendAlertPush::class, fn (SendAlertPush $job) => $job->alert->is($alert) && $job->resolution === null);
    }

    public function test_a_whole_campus_alert_has_no_pin_or_danger_zone(): void
    {
        Queue::fake([SendAlertPush::class]);

        $this->actingAs($this->admin())->post(route('admin.alerts.store'), $this->alertInput([
            'type' => 'typhoon', 'severity' => 'warning', 'latitude' => '', 'longitude' => '', 'danger_radius_m' => '50', 'building_codes' => [],
        ]))->assertSessionHasNoErrors();

        $alert = Alert::sole();
        $this->assertNull($alert->latitude);
        $this->assertNull($alert->longitude);
        $this->assertNull($alert->danger_radius_m);
        $this->assertSame([], $alert->building_codes);
        $this->assertSame('Whole campus', $alert->locationLabel());
    }

    public function test_an_alert_needs_a_type_title_and_message(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.alerts.store'), ['type' => 'volcano', 'title' => '', 'message' => ''])
            ->assertSessionHasErrors([
                'type' => 'Choose the type of emergency.',
                'title' => 'Give the alert a short title.',
                'message' => 'Say what is happening.',
            ]);

        $this->assertDatabaseEmpty('alerts');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[TestWith([['latitude' => '8.5800', 'longitude' => '124.7483871'], 'latitude', 'Put the pin on the campus map.'])]
    #[TestWith([['longitude' => ''], 'longitude', 'The longitude field is required when latitude is present.'])]
    #[TestWith([['danger_radius_m' => '75'], 'danger_radius_m', 'Choose a danger zone of 30, 50 or 100 m.'])]
    #[TestWith([['building_codes' => ['b16']], 'building_codes.0', 'Choose buildings from the map.'])]
    public function test_the_location_must_be_on_campus_with_a_listed_radius_and_real_buildings(array $input, string $field, string $message): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.alerts.store'), $this->alertInput($input))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseEmpty('alerts');
    }

    public function test_only_one_alert_can_be_active_at_a_time(): void
    {
        Queue::fake([SendAlertPush::class]);
        Alert::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.alerts.create'))->assertRedirect(route('admin.alerts.index'));
        $this->actingAs($admin)->post(route('admin.alerts.store'), $this->alertInput())
            ->assertSessionHasErrors(['form' => 'An alert is already active. Edit or resolve it first.']);

        $this->assertSame(1, Alert::count());
        Queue::assertNotPushed(SendAlertPush::class);
    }

    public function test_a_new_alert_can_be_sent_once_the_last_one_is_resolved(): void
    {
        Alert::factory()->resolved()->create();

        $this->actingAs($this->admin())->post(route('admin.alerts.store'), $this->alertInput())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Alert::active()->count());
    }

    public function test_an_admin_edits_the_wording_of_the_active_alert_without_a_new_push(): void
    {
        $alert = Alert::factory()->pinned()->create();
        Queue::fake([SendAlertPush::class]);

        $this->actingAs($this->admin())->put(route('admin.alerts.update', $alert), [
            'severity' => 'emergency',
            'title' => 'Fire at Building 03',
            'message' => 'A fire has been reported at Building 03.',
            'instructions' => '',
            'building_codes' => ['b10'],
        ])->assertRedirect(route('admin.alerts.index'));

        $alert->refresh();
        $this->assertSame('Fire at Building 03', $alert->title);
        $this->assertNull($alert->instructions);
        $this->assertSame(['b1'], $alert->building_codes, 'only the wording can change');
        $this->assertSame(AlertLogAction::Edited, $alert->logs()->sole()->action);
        Queue::assertNotPushed(SendAlertPush::class);
    }

    #[TestWith([AlertResolution::AllClear, 'All clear. Back to class.', 'Marked as All clear. Everyone sees it for the next 15 minutes.'])]
    #[TestWith([AlertResolution::FalseAlarm, 'False alarm. There is no fire.', 'Marked as False alarm. Everyone sees it for the next 15 minutes.'])]
    public function test_an_admin_resolves_the_active_alert_and_everyone_is_notified(AlertResolution $resolution, string $message, string $status): void
    {
        $alert = Alert::factory()->create();
        $admin = $this->admin();
        Queue::fake([SendAlertPush::class]);
        $this->freezeSecond();

        $this->actingAs($admin)
            ->post(route('admin.alerts.resolve', $alert), ['resolution' => $resolution->value, 'resolved_message' => $message])
            ->assertRedirect(route('admin.alerts.index'))
            ->assertSessionHas('status', $status);

        $alert->refresh();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertSame($resolution, $alert->resolution);
        $this->assertEquals(now(), $alert->resolved_at);
        $this->assertTrue($alert->resolver->is($admin));
        $this->assertSame($message, $alert->resolved_message);
        $this->assertSame(AlertLogAction::Resolved, $alert->logs()->sole()->action);
        Queue::assertPushed(SendAlertPush::class, fn (SendAlertPush $job) => $job->alert->is($alert) && $job->resolution === $resolution);
    }

    public function test_resolving_needs_how_it_ended_and_a_message(): void
    {
        $alert = Alert::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.alerts.resolve', $alert), ['resolution' => 'gone', 'resolved_message' => ''])
            ->assertSessionHasErrors([
                'resolution' => 'Choose "All clear" or "False alarm".',
                'resolved_message' => 'Tell everyone what to do now, e.g. that it is safe to go back inside.',
            ]);

        $this->assertTrue($alert->fresh()->isActive());
    }

    public function test_a_resolved_alert_cannot_be_edited_or_resolved_again(): void
    {
        $alert = Alert::factory()->resolved('First all clear.')->create();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.alerts.edit', $alert))->assertRedirect(route('admin.alerts.index'));
        $this->actingAs($admin)->put(route('admin.alerts.update', $alert), $this->alertInput(['title' => 'Changed']))
            ->assertSessionHasErrors('form');
        $this->actingAs($admin)->post(route('admin.alerts.resolve', $alert), ['resolution' => 'all_clear', 'resolved_message' => 'Second all clear.'])
            ->assertSessionHasErrors('form');

        $this->assertSame('Fire on campus', $alert->fresh()->title);
        $this->assertSame('First all clear.', $alert->fresh()->resolved_message);
        $this->assertDatabaseEmpty('alert_logs');
    }

    public function test_the_page_shows_the_active_alert_with_its_location_safe_count_and_the_history(): void
    {
        $admin = $this->admin();
        $this->travelTo('2026-11-08 06:14:00'); // 2:14 PM campus time.
        Alert::send(['type' => AlertType::Earthquake, ...AlertType::Earthquake->template(), 'is_drill' => true], $admin)
            ->resolve('The drill is over.', $admin, AlertResolution::FalseAlarm);
        $this->travel(1)->hour();
        $active = Alert::send([
            'type' => AlertType::Fire, ...AlertType::Fire->template(),
            'latitude' => self::NEE_LATITUDE, 'longitude' => self::NEE_LONGITUDE, 'danger_radius_m' => 30, 'building_codes' => ['b1'],
        ], $admin);
        SafetyCheck::markSafe($active, User::factory()->create());
        SafetyCheck::markSafe($active, User::factory()->create());

        $this->actingAs($admin)->get(route('admin.alerts.index'))
            ->assertOk()
            ->assertDontSee('>Send emergency alert</a>', false)
            ->assertSeeInOrder([
                'Active now: Fire', 'Fire on campus', 'NEE Building', '(30 m danger zone)',
                'Sent Nov 8, 2026, 3:14 PM by Campus Admin', '2', 'people marked themselves safe', 'End alert and notify everyone',
            ])
            ->assertSeeInOrder([
                'History',
                'Fire on campus', 'Active', 'NEE Building',
                'Earthquake', 'DRILL', 'False alarm', 'Whole campus', 'False alarm: The drill is over.',
                'Sent Nov 8, 2026, 2:14 PM by Campus Admin', 'Resolved Nov 8, 2026, 2:14 PM by Campus Admin',
            ]);
    }

    public function test_the_dashboard_has_a_send_button_or_the_active_alert(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.alerts.create'), false)
            ->assertSee('Send emergency alert');

        Alert::factory()->drill()->create(['title' => 'Earthquake drill']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('Drill alert active:')
            ->assertSee('Manage alert')
            ->assertDontSee(route('admin.alerts.create'), false);
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    public function test_only_admins_can_send_or_manage_alerts(UserRole $role): void
    {
        Queue::fake([SendAlertPush::class]);
        $alert = Alert::factory()->create();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get(route('admin.alerts.index'))->assertForbidden();
        $this->get(route('admin.alerts.create'))->assertForbidden();
        $this->post(route('admin.alerts.store'), $this->alertInput())->assertForbidden();
        $this->put(route('admin.alerts.update', $alert), $this->alertInput())->assertForbidden();
        $this->post(route('admin.alerts.resolve', $alert), ['resolution' => 'all_clear', 'resolved_message' => 'x'])->assertForbidden();

        $this->assertSame(1, Alert::count());
        $this->assertTrue($alert->fresh()->isActive());
        Queue::assertNotPushed(SendAlertPush::class);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->post(route('admin.alerts.store'), $this->alertInput())->assertRedirect(route('login'));

        $this->assertDatabaseEmpty('alerts');
    }
}
