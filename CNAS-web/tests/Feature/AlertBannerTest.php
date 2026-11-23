<?php

namespace Tests\Feature;

use App\Enums\AlertResolution;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The emergency banner and alarm takeover on every page, and the api/alerts/active endpoint they (and the app) poll.
 */
class AlertBannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pinned alerts name a real building (b1 NEE Building).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CampusMapSeeder::class);
    }

    public function test_the_endpoint_returns_null_without_an_alert(): void
    {
        $this->getJson(route('api.alerts.active'))
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_the_endpoint_returns_the_active_alert_with_its_location_to_anyone_without_admin_details(): void
    {
        $this->freezeSecond();
        $alert = Alert::factory()->drill()->pinned(30)->create();

        $this->getJson(route('api.alerts.active'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'id' => $alert->id,
                'version' => $alert->version(),
                'type' => 'fire',
                'type_label' => 'Fire',
                'severity' => 'emergency',
                'severity_label' => 'Emergency',
                'title' => 'Fire on campus',
                'message' => 'A fire has been reported.',
                'what_to_do' => 'Leave the building now. Go to the open field.',
                'instructions' => $alert->instructions,
                'is_drill' => true,
                'status' => 'active',
                'location' => 'NEE Building',
                'latitude' => 8.5409326,
                'longitude' => 124.7483871,
                'danger_radius_m' => 30,
                'building_codes' => ['b1'],
                'has_location' => true,
                'sent_at' => now()->toIso8601String(),
                'resolution' => null,
                'resolution_label' => null,
                'resolved_at' => null,
                'resolved_message' => null,
                'banner_expires_at' => null,
            ]]);
    }

    public function test_a_whole_campus_alert_has_no_location_fields(): void
    {
        Alert::factory()->create(['type' => 'typhoon']);

        $this->getJson(route('api.alerts.active'))
            ->assertJsonPath('data.location', 'Whole campus')
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null)
            ->assertJsonPath('data.danger_radius_m', null)
            ->assertJsonPath('data.building_codes', []);
    }

    public function test_a_resolved_alert_shows_how_it_ended_for_fifteen_minutes_then_nothing(): void
    {
        $this->freezeSecond();
        Alert::factory()->resolved('False alarm. Carry on.', AlertResolution::FalseAlarm)->create();

        $this->getJson(route('api.alerts.active'))
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution', 'false_alarm')
            ->assertJsonPath('data.resolution_label', 'False alarm')
            ->assertJsonPath('data.resolved_message', 'False alarm. Carry on.');

        $this->travel(14)->minutes();
        $this->getJson(route('api.alerts.active'))->assertJsonPath('data.status', 'resolved');
        $this->get(route('landing'))->assertSee('data-state="clear"', false);

        $this->travel(2)->minutes();
        $this->getJson(route('api.alerts.active'))->assertExactJson(['data' => null]);
        $this->get(route('landing'))->assertSee('data-state="none"', false);
    }

    public function test_the_api_says_when_the_green_banner_stops_showing(): void
    {
        $this->freezeSecond();
        Alert::factory()->create();

        $this->getJson(route('api.alerts.active'))->assertJsonPath('data.banner_expires_at', null);

        Alert::sole()->resolve('All clear.', User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson(route('api.alerts.active'))
            ->assertJsonPath('data.banner_expires_at', now()->addMinutes(Alert::ALL_CLEAR_MINUTES)->toIso8601String());
        $this->assertSame(15, Alert::ALL_CLEAR_MINUTES);
    }

    public function test_the_green_banner_has_a_close_button_and_no_location_while_the_red_one_keeps_it(): void
    {
        $banner = fn () => preg_match('/data-alert-banner.*?data-alert-takeover/s', $this->get(route('landing'))->getContent(), $found) ? $found[0] : '';

        Alert::factory()->pinned()->create();
        $this->assertStringContainsString('NEE Building', $banner());

        Alert::sole()->resolve('All clear.', User::factory()->create(['role' => UserRole::Admin]));
        $green = $banner();
        $this->assertStringContainsString('data-dismiss-banner', $green);
        $this->assertStringNotContainsString('NEE Building', $green);
    }

    public function test_admin_pages_do_not_show_the_green_banner_but_still_show_an_active_alert(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $alert = Alert::factory()->create(['created_by' => $admin]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('data-state="active"', false);

        $alert->resolve('All clear. Back to class.', $admin);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('data-state="none"', false)
            ->assertSee('data-hide-resolved', false);
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))->get(route('student.dashboard'))
            ->assertSee('data-state="clear"', false);
    }

    public function test_the_endpoint_sees_changes_straight_away(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->getJson(route('api.alerts.active'))->assertJsonPath('data', null);

        $alert = Alert::factory()->create(['created_by' => $admin]);
        $this->getJson(route('api.alerts.active'))->assertJsonPath('data.title', 'Fire on campus');

        $alert->revise(['title' => 'Fire at Building 03', 'message' => 'Leave now.'], $admin);
        $this->getJson(route('api.alerts.active'))->assertJsonPath('data.title', 'Fire at Building 03');

        $alert->resolve('All clear.', $admin);
        $this->getJson(route('api.alerts.active'))->assertJsonPath('data.status', 'resolved');
    }

    /**
     * @return array<string, array{string, ?UserRole}>
     */
    public static function pages(): array
    {
        return [
            'landing (guest)' => ['landing', null],
            'login (guest)' => ['login', null],
            'campus map (guest)' => ['navigate', null],
            'student portal' => ['student.dashboard', UserRole::Student],
            'faculty portal' => ['faculty.dashboard', UserRole::Faculty],
            'admin console' => ['admin.dashboard', UserRole::Admin],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_page_shows_the_active_alert(string $route, ?UserRole $role): void
    {
        Alert::factory()->drill()->pinned()->create();
        if ($role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        $this->get(route($route))
            ->assertOk()
            ->assertSee('data-alert-banner', false)
            ->assertSee('data-state="active"', false)
            ->assertSee('data-drill', false)
            ->assertSeeInOrder(['DRILL', 'Fire', 'NEE Building', 'Fire on campus', 'Leave the building now. Go to the open field.', 'A fire has been reported.', 'Show route to safety'])
            ->assertSee(route('navigate', ['evacuate' => 1]), false)
            ->assertSee(route('api.alerts.active'), false);
    }

    #[DataProvider('pages')]
    public function test_without_an_alert_the_banner_is_hidden_but_still_polls(string $route, ?UserRole $role): void
    {
        if ($role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        $this->get(route($route))
            ->assertOk()
            ->assertSee('data-state="none"', false)
            ->assertSee('data-poll-url="'.route('api.alerts.active').'"', false)
            ->assertSee('<link rel="manifest" href="'.asset('manifest.json').'">', false);
    }

    public function test_the_alarm_takeover_is_on_every_page_except_the_admin_console(): void
    {
        $this->get(route('landing'))->assertSee('data-alert-takeover', false);

        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.dashboard'))
            ->assertSee('data-alert-takeover', false);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.dashboard'))
            ->assertDontSee('data-alert-takeover', false);
    }

    public function test_only_signed_in_people_get_the_im_safe_button_and_it_remembers_their_answer(): void
    {
        $alert = Alert::factory()->create();
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->get(route('landing'))->assertDontSee('data-mark-safe', false);

        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertSee('action="'.route('alerts.safe', $alert).'"', false)
            ->assertDontSee('data-marked disabled', false);

        SafetyCheck::markSafe($alert, $student);

        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertSee('data-marked disabled', false);
    }

    public function test_a_resolved_alert_shows_its_resolution_and_message(): void
    {
        Alert::factory()->resolved('False alarm. Carry on.', AlertResolution::FalseAlarm)->create();

        $this->get(route('landing'))
            ->assertSee('data-state="clear"', false)
            ->assertSeeInOrder(['False alarm', 'Fire on campus', 'False alarm. Carry on.']);
    }
}
