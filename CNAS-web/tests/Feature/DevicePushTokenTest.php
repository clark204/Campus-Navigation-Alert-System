<?php

namespace Tests\Feature;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The mobile app's push token: POST/DELETE /api/push-tokens and unlinking on /api/logout.
 */
class DevicePushTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[abcDEF123_-xyz]';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return ['expo_token' => self::TOKEN, 'platform' => 'android', 'device_name' => 'Samsung Galaxy A15', ...$overrides];
    }

    public function test_a_guest_phone_is_saved_anonymously(): void
    {
        $this->freezeSecond();

        $this->postJson(route('api.push-tokens.store'), $this->payload())
            ->assertOk()
            ->assertExactJson(['subscribed' => true, 'linked' => false]);

        $token = DevicePushToken::sole();
        $this->assertSame(self::TOKEN, $token->expo_token);
        $this->assertNull($token->user_id);
        $this->assertSame('android', $token->platform);
        $this->assertSame('Samsung Galaxy A15', $token->device_name);
        $this->assertEquals(now(), $token->last_seen_at);
    }

    public function test_a_signed_in_phone_is_linked_to_the_account(): void
    {
        $student = User::factory()->create();

        $this->withToken($student->createToken('Pixel')->plainTextToken)
            ->postJson(route('api.push-tokens.store'), $this->payload())
            ->assertExactJson(['subscribed' => true, 'linked' => true]);

        $this->assertTrue(DevicePushToken::sole()->user->is($student));
    }

    public function test_sending_it_again_refreshes_the_same_row(): void
    {
        $this->postJson(route('api.push-tokens.store'), $this->payload())->assertOk();
        $this->travel(2)->days();

        $this->postJson(route('api.push-tokens.store'), $this->payload(['device_name' => 'Pixel 8']))->assertOk();

        $token = DevicePushToken::sole();
        $this->assertSame('Pixel 8', $token->device_name);
        $this->assertTrue($token->last_seen_at->isToday());
    }

    public function test_a_guest_call_never_unlinks_a_phone_from_its_account(): void
    {
        $student = User::factory()->create();
        $this->withToken($student->createToken('Pixel')->plainTextToken)->postJson(route('api.push-tokens.store'), $this->payload());
        $this->app['auth']->forgetGuards();

        $this->withoutToken()->postJson(route('api.push-tokens.store'), $this->payload())
            ->assertExactJson(['subscribed' => true, 'linked' => true]);
        // An invalid bearer token counts as a guest too.
        $this->withToken('1|not-a-real-token')->postJson(route('api.push-tokens.store'), $this->payload())->assertOk();

        $this->assertTrue(DevicePushToken::sole()->user->is($student));
    }

    #[TestWith(['not-a-token'])]
    #[TestWith(['ExponentPushToken[]'])]
    #[TestWith(['https://fcm.googleapis.com/fcm/send/abc'])]
    public function test_only_expo_push_tokens_are_accepted(string $value): void
    {
        $this->postJson(route('api.push-tokens.store'), $this->payload(['expo_token' => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expo_token');

        $this->assertDatabaseEmpty('device_push_tokens');
    }

    public function test_the_newer_expo_token_format_is_accepted(): void
    {
        $this->postJson(route('api.push-tokens.store'), $this->payload(['expo_token' => 'ExpoPushToken[abc123]']))->assertOk();

        $this->assertDatabaseCount('device_push_tokens', 1);
    }

    public function test_the_platform_must_be_android_or_ios(): void
    {
        $this->postJson(route('api.push-tokens.store'), $this->payload(['platform' => 'windows']))
            ->assertJsonValidationErrors('platform');
    }

    public function test_turning_alerts_off_deletes_the_token(): void
    {
        DevicePushToken::factory()->create(['expo_token' => self::TOKEN]);
        $other = DevicePushToken::factory()->create();

        $this->deleteJson(route('api.push-tokens.destroy'), ['expo_token' => self::TOKEN])->assertNoContent();

        $this->assertSame([$other->id], DevicePushToken::pluck('id')->all());
    }

    public function test_signing_out_keeps_the_phone_subscribed_as_a_guest(): void
    {
        $student = User::factory()->create();
        $token = DevicePushToken::factory()->for($student)->create(['expo_token' => self::TOKEN]);
        $otherPhone = DevicePushToken::factory()->for($student)->create();

        $this->withToken($student->createToken('Pixel')->plainTextToken)->postJson(route('api.logout'), ['expo_token' => self::TOKEN])->assertNoContent();

        $this->assertNull($token->fresh()->user_id);
        // Only this phone: the student's other phone stays linked.
        $this->assertTrue($otherPhone->fresh()->user->is($student));
    }

    public function test_signing_out_never_unlinks_another_account_s_phone(): void
    {
        $owner = User::factory()->create();
        $token = DevicePushToken::factory()->for($owner)->create(['expo_token' => self::TOKEN]);
        $student = User::factory()->create();

        $this->withToken($student->createToken('Pixel')->plainTextToken)->postJson(route('api.logout'), ['expo_token' => self::TOKEN])->assertNoContent();

        $this->assertTrue($token->fresh()->user->is($owner));
    }

    public function test_push_token_calls_are_rate_limited_per_token(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('api.push-tokens.store'), $this->payload())->assertOk();
        }

        $this->postJson(route('api.push-tokens.store'), $this->payload())->assertTooManyRequests();
        // Another phone on the same network is not affected.
        $this->postJson(route('api.push-tokens.store'), $this->payload(['expo_token' => 'ExponentPushToken[other]']))->assertOk();
    }
}
