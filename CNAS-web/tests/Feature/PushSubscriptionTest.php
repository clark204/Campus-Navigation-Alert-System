<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\PushSubscription;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * "Turn on emergency alerts": browsers save their push subscription; guests anonymously, signed-in users linked to
 * their account.
 */
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscription(array $overrides = []): array
    {
        return [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => 'BPublicKeyFromTheBrowser', 'auth' => 'authSecret'],
            'content_encoding' => 'aes128gcm',
            ...$overrides,
        ];
    }

    public function test_a_guest_subscribes_anonymously(): void
    {
        $this->postJson(route('push-subscriptions.store'), $this->subscription())
            ->assertOk()
            ->assertExactJson(['subscribed' => true]);

        $subscription = PushSubscription::sole();
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame('BPublicKeyFromTheBrowser', $subscription->public_key);
        $this->assertSame('authSecret', $subscription->auth_token);
        $this->assertNull($subscription->subscribable_id);
    }

    public function test_a_signed_in_user_s_subscription_is_linked_to_their_account(): void
    {
        $user = User::factory()->create();
        $this->postJson(route('push-subscriptions.store'), $this->subscription())->assertOk();

        // The same browser after signing in: the anonymous subscription now belongs to the user.
        $this->actingAs($user)->postJson(route('push-subscriptions.store'), $this->subscription())->assertOk();

        $subscription = PushSubscription::sole();
        $this->assertTrue($subscription->subscribable->is($user));
    }

    public function test_a_guest_cannot_take_a_subscription_away_from_an_account(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription(self::ENDPOINT, 'oldKey', 'oldAuth', 'aes128gcm');

        $this->postJson(route('push-subscriptions.store'), $this->subscription())->assertOk();

        $subscription = PushSubscription::sole();
        $this->assertTrue($subscription->subscribable->is($user));
        $this->assertSame('BPublicKeyFromTheBrowser', $subscription->public_key, 'the keys are refreshed');
    }

    #[TestWith(['http://fcm.googleapis.com/fcm/send/abc'])]
    #[TestWith(['https://127.0.0.1/internal'])]
    #[TestWith(['https://fcm.googleapis.com.evil.test/send'])]
    #[TestWith(['https://example.com/push'])]
    public function test_only_real_push_services_are_accepted(string $endpoint): void
    {
        $this->postJson(route('push-subscriptions.store'), $this->subscription(['endpoint' => $endpoint]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseEmpty('push_subscriptions');
    }

    #[TestWith(['https://updates.push.services.mozilla.com/wpush/v2/abc'])]
    #[TestWith(['https://web.push.apple.com/QGu2x'])]
    #[TestWith(['https://wns2-par02p.notify.windows.com/w/?token=abc'])]
    public function test_firefox_safari_and_windows_push_services_are_accepted(string $endpoint): void
    {
        $this->postJson(route('push-subscriptions.store'), $this->subscription(['endpoint' => $endpoint]))->assertOk();

        $this->assertSame($endpoint, PushSubscription::sole()->endpoint);
    }

    public function test_the_keys_are_required(): void
    {
        $this->postJson(route('push-subscriptions.store'), ['endpoint' => self::ENDPOINT])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
    }

    public function test_turning_alerts_off_deletes_the_subscription(): void
    {
        $this->postJson(route('push-subscriptions.store'), $this->subscription())->assertOk();

        $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => self::ENDPOINT])->assertNoContent();

        $this->assertDatabaseEmpty('push_subscriptions');
    }
}
