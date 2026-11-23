<?php

namespace Tests\Feature;

use App\Enums\AlertLogAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Jobs\SendHelpUpdatePush;
use App\Models\Alert;
use App\Models\DevicePushToken;
use App\Models\SafetyCheck;
use App\Models\User;
use App\Services\ExpoPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Help requests (docs/alerts.md): "I need help" with the person's location, its 15 s check-in (ping) while it is open,
 * and the admin's "I'm responding" and "Mark helped" on the web and in the app.
 */
class HelpRequestTest extends TestCase
{
    use RefreshDatabase;

    private const SPOT = ['latitude' => 8.5409326, 'longitude' => 124.7483871, 'accuracy' => 14.6];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'first_name' => 'Rita', 'last_name' => 'Reyes']);
    }

    private function emergency(): Alert
    {
        return Alert::send(['type' => AlertType::Fire, ...AlertType::Fire->template(), 'severity' => AlertSeverity::Emergency, 'is_drill' => false], $this->admin);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => UserRole::Student, 'first_name' => 'Ana', 'last_name' => 'Cruz']);
    }

    public function test_i_need_help_saves_the_shared_location(): void
    {
        $alert = $this->emergency();
        $this->freezeSecond();

        $this->actingAs($this->student())->postJson(route('alerts.help', $alert), ['note' => 'Library', ...self::SPOT])
            ->assertOk()
            ->assertJsonPath('help', ['status' => 'new', 'label' => 'Sent. The administrators can see your request.', 'responder_name' => null]);

        $check = SafetyCheck::sole();
        $this->assertSame([8.5409326, 124.7483871, 15], [$check->latitude, $check->longitude, $check->accuracy_m]);
        $this->assertEquals(now(), $check->located_at);
    }

    public function test_i_need_help_works_without_a_location(): void
    {
        $alert = $this->emergency();

        $this->actingAs($this->student())->postJson(route('alerts.help', $alert), ['note' => 'Room 201'])->assertOk();

        $this->assertFalse(SafetyCheck::sole()->hasLocation());
    }

    public function test_a_location_must_be_a_real_position(): void
    {
        $alert = $this->emergency();
        $this->actingAs($this->student());

        $this->postJson(route('alerts.help', $alert), ['latitude' => 8.5])->assertJsonValidationErrors('longitude');
        $this->postJson(route('alerts.help', $alert), ['latitude' => 95, 'longitude' => 124.7])->assertJsonValidationErrors('latitude');
        $this->postJson(route('alerts.help', $alert), [...self::SPOT, 'accuracy' => 9000])->assertJsonValidationErrors('accuracy');
        $this->assertDatabaseEmpty('safety_checks');
    }

    public function test_the_ping_updates_the_location_and_says_who_is_on_the_way(): void
    {
        $alert = $this->emergency();
        $student = $this->student();
        $check = SafetyCheck::markNeedHelp($alert, $student, null);
        $check->respond($this->admin);

        $this->actingAs($student)->postJson(route('alerts.help.ping', $alert), ['latitude' => 8.541, 'longitude' => 124.749, 'accuracy' => 8])
            ->assertOk()
            ->assertExactJson(['help' => ['status' => 'responding', 'label' => 'Rita Reyes is on the way.', 'responder_name' => 'Rita Reyes']]);

        $this->assertSame([8.541, 124.749, 8], [$check->fresh()->latitude, $check->fresh()->longitude, $check->fresh()->accuracy_m]);
    }

    public function test_the_ping_with_location_off_only_answers_the_state(): void
    {
        $alert = $this->emergency();
        $student = $this->student();
        SafetyCheck::markNeedHelp($alert, $student, null, self::SPOT);

        $this->actingAs($student)->postJson(route('alerts.help.ping', $alert))->assertOk()->assertJsonPath('help.status', 'new');

        $this->assertSame(8.5409326, SafetyCheck::sole()->latitude);
    }

    public function test_the_ping_stops_once_there_is_nothing_to_share(): void
    {
        $alert = $this->emergency();
        $student = $this->student();
        $this->actingAs($student);

        // Never asked.
        $this->postJson(route('alerts.help.ping', $alert), self::SPOT)->assertConflict()->assertJsonPath('help', null);

        // Marked helped: the last state comes back so the page can say so, and the position is not saved.
        $check = SafetyCheck::markNeedHelp($alert, $student, null);
        $check->markHelped($this->admin);
        $this->postJson(route('alerts.help.ping', $alert), self::SPOT)->assertConflict()->assertJsonPath('help.status', 'helped');
        $this->assertFalse($check->fresh()->hasLocation());

        // Answered "I'm safe" afterwards.
        SafetyCheck::markSafe($alert, $student);
        $this->postJson(route('alerts.help.ping', $alert), self::SPOT)->assertConflict()->assertJsonPath('help', null);

        // The alert is over.
        SafetyCheck::markNeedHelp($alert, $student, null);
        $alert->resolve('All clear.', $this->admin);
        $this->postJson(route('alerts.help.ping', $alert), self::SPOT)->assertConflict()->assertJsonPath('message', 'This alert is over.');
    }

    public function test_answering_again_resets_the_request(): void
    {
        $alert = $this->emergency();
        $student = $this->student();
        $check = SafetyCheck::markNeedHelp($alert, $student, null, self::SPOT);
        $check->respond($this->admin);
        $check->markHelped($this->admin);

        SafetyCheck::markSafe($alert, $student);
        $this->assertNull(SafetyCheck::sole()->helpStatus());
        $this->assertFalse(SafetyCheck::sole()->hasLocation());

        SafetyCheck::markNeedHelp($alert, $student, 'Again');
        $this->assertSame('new', SafetyCheck::sole()->helpStatus()->value);
        $this->assertNull(SafetyCheck::sole()->responder_id);
    }

    public function test_an_admin_responds_and_marks_helped_from_the_web(): void
    {
        $alert = $this->emergency();
        $student = $this->student();
        $check = SafetyCheck::markNeedHelp($alert, $student, null);

        $this->actingAs($this->admin)->from(route('admin.alerts.index'))
            ->post(route('admin.alerts.help.respond', [$alert, $check]))
            ->assertRedirect(route('admin.alerts.index'))
            ->assertSessionHas('status', 'You are responding to Ana Cruz.');
        Queue::assertPushed(SendHelpUpdatePush::class, fn (SendHelpUpdatePush $job) => $job->check->is($check));
        $this->assertSame('responding', $check->fresh()->helpStatus()->value);

        $this->actingAs($this->admin)->postJson(route('admin.alerts.help.helped', [$alert, $check]))
            ->assertOk()
            ->assertJsonPath('message', 'Ana Cruz is marked as helped.')
            ->assertJsonPath('help_request.status', 'helped')
            ->assertJsonPath('help_request.helped_by_name', 'Rita Reyes');

        $this->assertSame(
            [[AlertLogAction::HelpResponding, 'Ana Cruz'], [AlertLogAction::HelpResolved, 'Ana Cruz']],
            $alert->logs()->whereIn('action', [AlertLogAction::HelpResponding, AlertLogAction::HelpResolved])->orderBy('id')->get()->map(fn ($log) => [$log->action, $log->detail])->all(),
        );
        // Already helped: nothing changes.
        $this->actingAs($this->admin)->postJson(route('admin.alerts.help.respond', [$alert, $check]))
            ->assertConflict()->assertJsonPath('message', 'This person is already marked as helped.');
    }

    public function test_the_app_admin_uses_the_same_actions(): void
    {
        $alert = $this->emergency();
        $check = SafetyCheck::markNeedHelp($alert, $this->student(), null);
        Sanctum::actingAs($this->admin);

        $this->postJson(route('api.alerts.help.respond', [$alert, $check]))->assertOk()->assertJsonPath('help_request.responder_name', 'Rita Reyes');
    }

    public function test_only_admins_handle_help_requests(): void
    {
        $alert = $this->emergency();
        $check = SafetyCheck::markNeedHelp($alert, $this->student(), null);
        $trusted = User::factory()->create(['role' => UserRole::Faculty, 'alert_trusted_at' => now()]);

        $this->actingAs($this->student())->post(route('admin.alerts.help.respond', [$alert, $check]))->assertForbidden();
        Sanctum::actingAs($trusted);
        $this->postJson(route('api.alerts.help.respond', [$alert, $check]))->assertForbidden();

        $this->assertNull($check->fresh()->responder_id);
    }

    public function test_a_help_request_must_belong_to_the_alert(): void
    {
        $alert = $this->emergency();
        $alert->resolve('Over.', $this->admin);
        $other = $this->emergency();
        $check = SafetyCheck::markNeedHelp($alert, $this->student(), null);

        $this->actingAs($this->admin)->postJson(route('admin.alerts.help.respond', [$other, $check]))->assertNotFound();

        $safe = SafetyCheck::markSafe($other, User::factory()->create());
        $this->actingAs($this->admin)->postJson(route('admin.alerts.help.respond', [$other, $safe]))->assertNotFound();
    }

    public function test_an_ended_alert_takes_no_more_actions(): void
    {
        $alert = $this->emergency();
        $check = SafetyCheck::markNeedHelp($alert, $this->student(), null);
        $alert->resolve('Over.', $this->admin);

        $this->actingAs($this->admin)->postJson(route('admin.alerts.help.helped', [$alert, $check]))
            ->assertConflict()->assertJsonPath('message', 'This alert is over.');
    }

    public function test_the_admin_page_shows_the_help_queue_and_its_live_panel(): void
    {
        $alert = $this->emergency();
        $waiting = SafetyCheck::markNeedHelp($alert, $this->student(), 'Stuck on the stairs', self::SPOT);
        $later = SafetyCheck::markNeedHelp($alert, User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Lim']), null);
        $later->respond($this->admin);

        $this->actingAs($this->admin)->get(route('admin.alerts.index'))
            ->assertOk()
            ->assertSee('data-help-workspace', false)
            ->assertSee('2 waiting')
            ->assertSee(route('admin.alerts.help', $alert), false)
            // Waiting first, then on the way.
            ->assertSeeInOrder(['Ana Cruz', '“Stuck on the stairs”', 'Live, updated', 'I’m responding', 'Ben Lim', 'You are on the way', 'No location shared', 'Mark helped']);

        $this->actingAs($this->admin)->get(route('admin.alerts.help', $alert))
            ->assertOk()
            ->assertSee('data-help-points', false)
            ->assertSee(route('admin.alerts.help.respond', [$alert, $waiting]), false);
    }

    public function test_the_app_live_view_lists_help_requests_with_locations(): void
    {
        $alert = $this->emergency();
        SafetyCheck::markNeedHelp($alert, $this->student(), 'Library', self::SPOT);
        Sanctum::actingAs($this->admin);

        $this->getJson(route('api.alerts.safety', $alert))
            ->assertOk()
            ->assertJsonPath('help_requests.0.name', 'Ana Cruz')
            ->assertJsonPath('help_requests.0.status', 'new')
            ->assertJsonPath('help_requests.0.latitude', 8.5409326)
            ->assertJsonPath('help_requests.0.accuracy_m', 15);
    }

    public function test_the_app_sees_its_own_help_state_in_the_active_alert(): void
    {
        $alert = $this->emergency();
        $student = $this->student();
        SafetyCheck::markNeedHelp($alert, $student, null)->respond($this->admin);

        $this->withToken($student->createToken('Phone')->plainTextToken)->getJson(route('api.alerts.active'))
            ->assertJsonPath('data.my_safety_status', 'need_help')
            ->assertJsonPath('data.my_help.label', 'Rita Reyes is on the way.');
    }

    public function test_the_person_s_phones_get_on_the_way_on_the_warnings_channel(): void
    {
        Http::fake([ExpoPush::SEND_URL => fn (Request $request) => Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'ticket'], $request->data())])]);
        $alert = $this->emergency();
        $student = $this->student();
        DevicePushToken::factory()->create(['user_id' => $student->id, 'expo_token' => 'ExponentPushToken[ana]']);
        DevicePushToken::factory()->create(['expo_token' => 'ExponentPushToken[someone-else]']);
        $check = SafetyCheck::markNeedHelp($alert, $student, null);
        $check->respond($this->admin);

        (new SendHelpUpdatePush($check))->handle(app(ExpoPush::class));

        $sent = Http::recorded()->flatMap(fn (array $pair) => $pair[0]->data())->values();
        $this->assertSame(['ExponentPushToken[ana]'], $sent->pluck('to')->all());
        $this->assertSame(['Rita Reyes is on the way', ExpoPush::CHANNEL_WARNING], [$sent[0]['title'], $sent[0]['channelId']]);
    }

    public function test_locations_are_deleted_with_the_answers(): void
    {
        $alert = $this->emergency();
        SafetyCheck::markNeedHelp($alert, $this->student(), null, self::SPOT);
        $alert->resolve('Over.', $this->admin);
        $this->travel(SafetyCheck::RETENTION_DAYS + 1)->days();

        $this->artisan('model:prune', ['--model' => [SafetyCheck::class]]);

        $this->assertDatabaseEmpty('safety_checks');
    }
}
