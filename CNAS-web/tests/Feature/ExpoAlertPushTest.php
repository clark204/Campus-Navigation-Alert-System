<?php

namespace Tests\Feature;

use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Jobs\CheckExpoPushReceipts;
use App\Jobs\FanOutExpoAlertPush;
use App\Jobs\SendExpoAlertPush;
use App\Models\Alert;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\ExpoPush;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The phone push for alerts through the Expo push service: what is sent, batches of 100, one job per batch, and
 * tokens Expo reports as DeviceNotRegistered are deleted.
 */
class ExpoAlertPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned alerts name a real building (b1 NEE Building) in the push text.
        $this->seed(CampusMapSeeder::class);
        Http::preventStrayRequests();
    }

    /**
     * Expo answers every send with an ok ticket per message (ids "ticket-0", "ticket-1", …) unless $tickets says
     * otherwise, and every receipt check with $receipts.
     *
     * @param  array<int, array<string, mixed>>  $tickets  By message index.
     * @param  array<string, array<string, mixed>>  $receipts
     */
    private function fakeExpo(array $tickets = [], array $receipts = []): void
    {
        Http::fake([
            ExpoPush::SEND_URL => fn (Request $request) => Http::response(['data' => array_map(
                fn (int $index) => $tickets[$index] ?? ['status' => 'ok', 'id' => "ticket-{$index}"],
                array_keys($request->data()),
            )]),
            ExpoPush::RECEIPTS_URL => Http::response(['data' => $receipts]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentMessages(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => $pair[0]->url() === ExpoPush::SEND_URL)
            ->flatMap(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_sending_an_emergency_pushes_every_phone_with_the_alarm(): void
    {
        $this->fakeExpo();
        $student = DevicePushToken::factory()->for(User::factory())->create(['expo_token' => 'ExponentPushToken[student]']);
        $guest = DevicePushToken::factory()->create(['expo_token' => 'ExponentPushToken[guest]']);

        $alert = Alert::send([
            'type' => AlertType::Fire,
            'severity' => AlertSeverity::Emergency,
            'title' => 'Fire at the NEE Building',
            'message' => 'Smoke on the 2nd floor.',
            'instructions' => "Leave the building now.\nDo not use the stairs near the fire.",
            'building_codes' => ['b1'],
        ], User::factory()->create(['role' => UserRole::Admin]));

        $messages = $this->sentMessages();
        $this->assertSame([$student->expo_token, $guest->expo_token], array_column($messages, 'to'));
        $this->assertSame([
            'to' => $student->expo_token,
            'title' => 'EMERGENCY · FIRE',
            'body' => 'NEE Building: Leave the building now.',
            'data' => ['alert_id' => $alert->id, 'screen' => 'alert'],
            'priority' => 'high',
            'channelId' => 'emergency',
            'sound' => 'alarm.wav',
            'tag' => "cnas-alert-{$alert->id}",
            'collapseId' => "cnas-alert-{$alert->id}",
            'ttl' => 3600,
        ], $messages[0]);
    }

    public function test_a_drill_uses_the_drill_channel_and_sound(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->create();

        (new SendExpoAlertPush(Alert::factory()->drill()->create(), null, DevicePushToken::pluck('id')->all()))->handle(app(ExpoPush::class));

        $message = $this->sentMessages()[0];
        $this->assertStringStartsWith('DRILL · ', $message['title']);
        $this->assertSame('drill', $message['channelId']);
        $this->assertSame('drill.wav', $message['sound']);
        $this->assertSame('high', $message['priority']);
    }

    public function test_a_warning_and_an_advisory_are_ordinary_pushes_with_the_chime(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->create();
        $ids = DevicePushToken::pluck('id')->all();

        (new SendExpoAlertPush(Alert::factory()->warning()->create(), null, $ids))->handle(app(ExpoPush::class));
        (new SendExpoAlertPush(Alert::factory()->advisory()->create(), null, $ids))->handle(app(ExpoPush::class));

        [$warning, $advisory] = $this->sentMessages();
        $this->assertStringStartsWith('WARNING · ', $warning['title']);
        $this->assertStringStartsWith('ADVISORY · ', $advisory['title']);
        foreach ([$warning, $advisory] as $message) {
            $this->assertSame('warning', $message['channelId']);
            $this->assertSame('chime.wav', $message['sound']);
            $this->assertSame('default', $message['priority']);
        }
    }

    public function test_the_all_clear_replaces_the_alert_s_notification(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->create();
        $alert = Alert::factory()->create();

        $alert->resolve('All clear. It is safe to go back to your classes.', User::factory()->create(['role' => UserRole::Admin]));

        $message = $this->sentMessages()[0];
        $this->assertSame('ALL CLEAR · FIRE', $message['title']);
        $this->assertSame('All clear. It is safe to go back to your classes.', $message['body']);
        $this->assertSame("cnas-alert-{$alert->id}", $message['tag']);
        $this->assertSame("cnas-alert-{$alert->id}", $message['collapseId']);
        $this->assertSame('warning', $message['channelId']);
        $this->assertSame('default', $message['priority']);
    }

    public function test_a_false_alarm_says_so(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->create();
        $alert = Alert::factory()->create();

        $alert->resolve('False alarm. There is no emergency.', User::factory()->create(['role' => UserRole::Admin]), AlertResolution::FalseAlarm);

        $this->assertSame('FALSE ALARM · FIRE', $this->sentMessages()[0]['title']);
    }

    public function test_a_level_change_is_pushed_again(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->create();
        $alert = Alert::factory()->warning()->create();

        $alert->changeSeverity(AlertSeverity::Emergency, User::factory()->create(['role' => UserRole::Admin]));

        $this->assertSame('emergency', $this->sentMessages()[0]['channelId']);
    }

    public function test_one_job_per_hundred_phones(): void
    {
        Queue::fake();
        DevicePushToken::factory()->count(250)->create();

        (new FanOutExpoAlertPush(Alert::factory()->create()))->handle();

        Queue::assertPushed(SendExpoAlertPush::class, 3);
        $sizes = Queue::pushed(SendExpoAlertPush::class)->map(fn (SendExpoAlertPush $job) => count($job->tokenIds))->all();
        $this->assertSame([100, 100, 50], $sizes);
    }

    public function test_sending_an_alert_queues_the_phone_push_next_to_the_browser_push(): void
    {
        Bus::fake();

        $alert = Alert::send(['type' => AlertType::Fire, 'severity' => AlertSeverity::Emergency, 'title' => 'Fire', 'message' => 'Leave now.'], User::factory()->create(['role' => UserRole::Admin]));

        Bus::assertDispatched(FanOutExpoAlertPush::class, fn (FanOutExpoAlertPush $job) => $job->alert->is($alert) && $job->resolution === null);
    }

    public function test_no_phones_means_no_request(): void
    {
        $this->fakeExpo();

        (new FanOutExpoAlertPush(Alert::factory()->create()))->handle();

        Http::assertNothingSent();
    }

    public function test_a_token_expo_rejects_at_once_is_deleted(): void
    {
        $this->fakeExpo([1 => ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]]);
        $kept = DevicePushToken::factory()->create();
        $gone = DevicePushToken::factory()->create();

        (new SendExpoAlertPush(Alert::factory()->create(), null, [$kept->id, $gone->id]))->handle(app(ExpoPush::class));

        $this->assertSame([$kept->id], DevicePushToken::pluck('id')->all());
    }

    public function test_receipts_are_checked_fifteen_minutes_later_and_unregistered_phones_removed(): void
    {
        Queue::fake([CheckExpoPushReceipts::class]);
        $this->fakeExpo(receipts: [
            'ticket-0' => ['status' => 'ok'],
            'ticket-1' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-2' => ['status' => 'error', 'details' => ['error' => 'MessageRateExceeded']],
        ]);
        $tokens = DevicePushToken::factory()->count(3)->create();

        (new SendExpoAlertPush(Alert::factory()->create(), null, $tokens->pluck('id')->all()))->handle(app(ExpoPush::class));

        Queue::assertPushed(CheckExpoPushReceipts::class, function (CheckExpoPushReceipts $job) {
            $this->assertSame(['ticket-0', 'ticket-1', 'ticket-2'], array_keys($job->tokensByReceipt));
            $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $job->delay->timestamp, 2);
            $job->handle(app(ExpoPush::class));

            return true;
        });
        // Only the DeviceNotRegistered one; a rate limit is not the phone's fault.
        $this->assertSame([$tokens[0]->id, $tokens[2]->id], DevicePushToken::orderBy('id')->pluck('id')->all());
        Http::assertSent(fn (Request $request) => $request->url() === ExpoPush::RECEIPTS_URL && $request['ids'] === ['ticket-0', 'ticket-1', 'ticket-2']);
    }

    public function test_expo_being_down_fails_the_batch_so_it_is_retried(): void
    {
        Http::fake([ExpoPush::SEND_URL => Http::response(['errors' => [['code' => 'INTERNAL']]], 503)]);
        DevicePushToken::factory()->create();
        $job = new SendExpoAlertPush(Alert::factory()->create(), null, DevicePushToken::pluck('id')->all());

        $this->assertSame(3, $job->tries);
        $this->expectException(RuntimeException::class);

        $job->handle(app(ExpoPush::class));
    }

    public function test_the_access_token_is_sent_only_when_set(): void
    {
        $this->fakeExpo();
        DevicePushToken::factory()->create();
        $ids = DevicePushToken::pluck('id')->all();

        (new SendExpoAlertPush(Alert::factory()->create(), null, $ids))->handle(app(ExpoPush::class));
        config(['services.expo.access_token' => 'expo-secret']);
        (new SendExpoAlertPush(Alert::factory()->create(), null, $ids))->handle(app(ExpoPush::class));

        $headers = Http::recorded()
            ->filter(fn (array $pair) => $pair[0]->url() === ExpoPush::SEND_URL)
            ->map(fn (array $pair) => $pair[0]->header('Authorization'))
            ->values()
            ->all();
        $this->assertSame([[], ['Bearer expo-secret']], $headers);
    }

    public function test_phones_that_turned_alerts_off_since_the_fan_out_are_skipped(): void
    {
        $this->fakeExpo();
        $tokens = DevicePushToken::factory()->count(2)->create();
        $tokens[1]->delete();

        (new SendExpoAlertPush(Alert::factory()->create(), null, $tokens->pluck('id')->all()))->handle(app(ExpoPush::class));

        $this->assertSame([$tokens[0]->expo_token], array_column($this->sentMessages(), 'to'));
    }
}
