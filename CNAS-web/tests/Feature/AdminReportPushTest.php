<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SafetyStatus;
use App\Enums\UserRole;
use App\Jobs\CheckExpoPushReceipts;
use App\Jobs\SendAdminReportPush;
use App\Models\Alert;
use App\Models\Concern;
use App\Models\DevicePushToken;
use App\Models\SafetyCheck;
use App\Models\User;
use App\Services\AdminReport;
use App\Services\ExpoPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reports for admins (docs/settings.md): a new concern and "I need help" are pushed to every admin's phone on the
 * "admin" channel, and to no one else. Also "I need help" itself (web and app).
 */
class AdminReportPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fakeExpo(array $tickets = []): void
    {
        Http::fake([
            ExpoPush::SEND_URL => fn (Request $request) => Http::response(['data' => array_map(
                fn (int $index) => $tickets[$index] ?? ['status' => 'ok', 'id' => "ticket-{$index}"],
                array_keys($request->data()),
            )]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentMessages(): array
    {
        return Http::recorded()->flatMap(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function emergency(bool $drill = true): Alert
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Queue::fake();

        return Alert::send(['type' => AlertType::Fire, ...AlertType::Fire->template(), 'severity' => AlertSeverity::Emergency, 'is_drill' => $drill], $admin);
    }

    public function test_a_report_goes_only_to_phones_signed_in_to_admin_accounts(): void
    {
        $this->fakeExpo();
        Queue::fake([CheckExpoPushReceipts::class]);
        DevicePushToken::factory()->for(User::factory()->create(['role' => UserRole::Admin]))->create(['expo_token' => 'ExponentPushToken[admin]']);
        DevicePushToken::factory()->for(User::factory()->create(['role' => UserRole::Staff]))->create(['expo_token' => 'ExponentPushToken[staff]']);
        DevicePushToken::factory()->create(['expo_token' => 'ExponentPushToken[guest]']);
        $concern = Concern::factory()->create(['message' => 'The library is missing from the map.']);

        (new SendAdminReportPush(AdminReport::concernReceived($concern)))->handle(app(ExpoPush::class));

        $messages = $this->sentMessages();
        $this->assertSame(['ExponentPushToken[admin]'], array_column($messages, 'to'));
        $this->assertSame('New message from A visitor', $messages[0]['title']);
        $this->assertStringContainsString('The library is missing from the map.', $messages[0]['body']);
        $this->assertSame(['screen' => 'concern', 'concern_id' => $concern->id], $messages[0]['data']);
        $this->assertSame(['admin_reports', 'high', 'default'], [$messages[0]['channelId'], $messages[0]['priority'], $messages[0]['sound']]);
        Queue::assertPushed(CheckExpoPushReceipts::class);
    }

    public function test_an_admin_phone_that_is_gone_is_forgotten(): void
    {
        $this->fakeExpo([0 => ['status' => 'error', 'details' => ['error' => ExpoPush::DEVICE_NOT_REGISTERED]]]);
        $token = DevicePushToken::factory()->for(User::factory()->create(['role' => UserRole::Admin]))->create();

        (new SendAdminReportPush(AdminReport::concernReceived(Concern::factory()->create())))->handle(app(ExpoPush::class));

        $this->assertModelMissing($token);
    }

    public function test_no_admin_phone_means_no_request_to_expo(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->for(User::factory()->create(['role' => UserRole::Student]))->create();

        (new SendAdminReportPush(AdminReport::concernReceived(Concern::factory()->create())))->handle(app(ExpoPush::class));

        Http::assertNothingSent();
    }

    public function test_a_new_concern_from_the_app_queues_the_report(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Student]));

        $this->postJson(route('api.concerns.store'), ['topic' => 'map', 'message' => 'ComLab 3 is upstairs.'])->assertCreated();

        Queue::assertPushed(SendAdminReportPush::class, fn (SendAdminReportPush $job) => $job->report->data === ['screen' => 'concern', 'concern_id' => Concern::sole()->id]);
    }

    public function test_i_need_help_from_the_app_is_saved_with_the_note_and_reported(): void
    {
        $alert = $this->emergency();
        $student = User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Ana', 'last_name' => 'Cruz']);
        Sanctum::actingAs($student);

        $this->postJson(route('api.alerts.help', $alert), ['note' => '  2nd floor, NEE 201. Hurt my ankle.  '])
            ->assertOk()
            ->assertJsonPath('status', 'need_help');

        $check = SafetyCheck::sole();
        $this->assertSame([SafetyStatus::NeedHelp, '2nd floor, NEE 201. Hurt my ankle.'], [$check->status, $check->note]);
        Queue::assertPushed(SendAdminReportPush::class, fn (SendAdminReportPush $job) => $job->report->title === 'Ana Cruz needs help'
            && $job->report->body === 'DRILL. 2nd floor, NEE 201. Hurt my ankle.'
            && $job->report->data === ['screen' => 'safety', 'alert_id' => $alert->id]);
        $this->withToken($student->createToken('Phone')->plainTextToken)->getJson(route('api.alerts.active'))->assertJsonPath('data.my_safety_status', 'need_help');
    }

    public function test_i_need_help_from_the_web_and_then_im_safe_replaces_it(): void
    {
        $alert = $this->emergency(drill: false);
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->actingAs($student)->from('/student')->post(route('alerts.help', $alert), ['note' => ''])->assertRedirect('/student');
        $this->assertSame(SafetyStatus::NeedHelp, SafetyCheck::sole()->status);
        $this->actingAs($student)->get('/student')->assertSee('Help requested');

        $this->actingAs($student)->postJson(route('alerts.safe', $alert))->assertOk();
        $this->assertSame([SafetyStatus::Safe, null], [SafetyCheck::sole()->status, SafetyCheck::sole()->note]);
    }

    public function test_i_need_help_follows_the_im_safe_rules(): void
    {
        $alert = $this->emergency();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Student]));

        $this->postJson(route('api.alerts.help', $alert), ['note' => str_repeat('x', 201)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'Keep it short: 200 characters or fewer.']);

        $alert->changeSeverity(AlertSeverity::Warning, User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson(route('api.alerts.help', $alert))->assertConflict()->assertJsonPath('message', 'Only an emergency asks whether you are safe.');

        $alert->resolve('Over.', User::factory()->create(['role' => UserRole::Admin]));
        $this->postJson(route('api.alerts.help', $alert))->assertConflict()->assertJsonPath('message', 'This alert is over.');
        $this->assertDatabaseEmpty('safety_checks');
        $this->postJson(route('api.alerts.help', $alert))->assertConflict();
    }

    public function test_the_live_view_counts_need_help_and_lists_them_first(): void
    {
        $alert = $this->emergency();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->travel(-1)->minutes();
        SafetyCheck::markNeedHelp($alert, User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Ana', 'last_name' => 'Cruz']), 'Gym, near the stage');
        $this->travelBack();
        SafetyCheck::markSafe($alert, User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Ben', 'last_name' => 'Reyes']));
        Sanctum::actingAs($admin);

        $this->getJson(route('api.alerts.safety', $alert))
            ->assertOk()
            ->assertJsonPath('need_help', 1)
            ->assertJsonPath('safe', 1)
            ->assertJsonPath('not_answered', 0)
            ->assertJsonPath('people.*.name', ['Ana Cruz', 'Ben Reyes'])
            ->assertJsonPath('people.0.status', 'need_help')
            ->assertJsonPath('people.0.note', 'Gym, near the stage');

        $this->actingAs($admin)->get(route('admin.alerts.index'))->assertSeeInOrder(['1', 'need help', 'Needs help', 'Ana Cruz', 'Gym, near the stage', 'Ben Reyes']);
    }
}
