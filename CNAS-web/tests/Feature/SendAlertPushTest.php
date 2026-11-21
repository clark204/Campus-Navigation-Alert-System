<?php

namespace Tests\Feature;

use App\Enums\AlertResolution;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The queued push for an alert reaches every subscription (users and guests) with the right content, and
 * subscriptions the push service reports as gone are removed.
 */
class SendAlertPushTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pinned alerts name a real building (b1 NEE Building) in the push text.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CampusMapSeeder::class);
    }

    /** @var array<string, array<string, mixed>> Payloads sent, by endpoint. */
    private array $sent = [];

    /**
     * Replace the push service (it leaves the process): record each message and answer with these status codes.
     *
     * @param  array<string, int>  $statusByEndpoint
     */
    private function fakePushService(array $statusByEndpoint = []): void
    {
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')->andReturnUsing(function (Subscription $subscription, string $payload) {
            $this->sent[$subscription->getEndpoint()] = json_decode($payload, true);
        });
        $webPush->shouldReceive('flush')->andReturnUsing(function () use ($statusByEndpoint) {
            foreach (array_keys($this->sent) as $endpoint) {
                $status = $statusByEndpoint[$endpoint] ?? 201;
                yield new MessageSentReport(new Request('POST', $endpoint), new Response($status), $status < 400);
            }
        });

        $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, $this->app->make(ReportHandler::class)));
    }

    private function subscribe(string $endpoint, ?User $user = null): void
    {
        $user
            ? $user->updatePushSubscription($endpoint, 'key', 'auth', 'aes128gcm')
            : PushSubscription::create(['endpoint' => $endpoint, 'public_key' => 'key', 'auth_token' => 'auth', 'content_encoding' => 'aes128gcm']);
    }

    public function test_an_alert_is_pushed_to_every_user_and_guest_subscription(): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/student', User::factory()->create());
        $this->subscribe('https://fcm.googleapis.com/fcm/send/guest');
        $this->fakePushService();
        $alert = Alert::factory()->pinned()->create(['instructions' => "Leave the building now.\nDo not use the stairs near the fire."]);

        (new SendAlertPush($alert))->handle();

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/student', 'https://fcm.googleapis.com/fcm/send/guest'], array_keys($this->sent));
        $payload = $this->sent['https://fcm.googleapis.com/fcm/send/guest'];
        $this->assertSame('EMERGENCY · FIRE', $payload['title']);
        $this->assertSame('NEE Building: Leave the building now.', $payload['body']);
        $this->assertTrue($payload['requireInteraction']);
        $this->assertSame([500, 200, 500, 200, 500, 200, 500], $payload['vibrate']);
        $this->assertSame("cnas-alert-{$alert->id}", $payload['tag']);
        $this->assertSame(route('navigate', ['alert' => $alert->id]), $payload['data']['url']);
    }

    public function test_a_drill_says_so_in_the_title(): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/guest');
        $this->fakePushService();

        (new SendAlertPush(Alert::factory()->drill()->create(['type' => 'earthquake'])))->handle();

        $this->assertSame('DRILL · EARTHQUAKE', $this->sent['https://fcm.googleapis.com/fcm/send/guest']['title']);
    }

    #[TestWith([AlertResolution::AllClear, 'ALL CLEAR · FIRE'])]
    #[TestWith([AlertResolution::FalseAlarm, 'FALSE ALARM · FIRE'])]
    public function test_the_end_of_an_alert_replaces_its_notification(AlertResolution $resolution, string $title): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/guest');
        $this->fakePushService();
        $alert = Alert::factory()->resolved('It is safe to go back inside.', $resolution)->create();

        (new SendAlertPush($alert, $resolution))->handle();

        $payload = $this->sent['https://fcm.googleapis.com/fcm/send/guest'];
        $this->assertSame($title, $payload['title']);
        $this->assertSame('It is safe to go back inside.', $payload['body']);
        $this->assertSame("cnas-alert-{$alert->id}", $payload['tag']);
        $this->assertArrayNotHasKey('requireInteraction', $payload);
    }

    public function test_subscriptions_the_push_service_reports_as_gone_are_removed(): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/working');
        $this->subscribe('https://fcm.googleapis.com/fcm/send/expired');
        $this->subscribe('https://updates.push.services.mozilla.com/wpush/v2/unknown');
        $this->fakePushService([
            'https://fcm.googleapis.com/fcm/send/expired' => 410,
            'https://updates.push.services.mozilla.com/wpush/v2/unknown' => 404,
        ]);

        (new SendAlertPush(Alert::factory()->create()))->handle();

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/working'], PushSubscription::pluck('endpoint')->all());
    }

    public function test_a_failed_delivery_keeps_the_subscription(): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/busy');
        $this->fakePushService(['https://fcm.googleapis.com/fcm/send/busy' => 429]);

        (new SendAlertPush(Alert::factory()->create()))->handle();

        $this->assertSame(1, PushSubscription::count());
    }
}
