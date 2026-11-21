<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginChannel;
use App\Enums\UserRole;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Successful sign-ins (web and mobile app) are recorded for the admin's sign-in history, and sign-outs close them.
 */
class LoginActivityTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';

    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private function student(): User
    {
        return User::factory()->create(['school_id' => '20240001', 'role' => UserRole::Student]);
    }

    public function test_a_web_sign_in_is_recorded_with_its_channel_ip_and_device(): void
    {
        $student = $this->student();
        $this->freezeSecond();

        $this->withHeader('User-Agent', self::WINDOWS_CHROME)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('login.store'), ['login' => '20240001', 'password' => 'password'])
            ->assertRedirect(route('student.dashboard'));

        $activity = LoginActivity::sole();
        $this->assertTrue($activity->user->is($student));
        $this->assertSame(LoginChannel::Web, $activity->channel);
        $this->assertSame('203.0.113.7', $activity->ip_address);
        $this->assertSame(self::WINDOWS_CHROME, $activity->user_agent);
        $this->assertSame('Chrome on Windows', $activity->device);
        $this->assertTrue($activity->logged_in_at->eq(now()));
        $this->assertNull($activity->logged_out_at);
        $this->assertNull($activity->personal_access_token_id);
    }

    public function test_a_failed_sign_in_is_not_recorded(): void
    {
        $this->student();

        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'wrong'])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('login_activities', 0);
    }

    public function test_web_sign_out_fills_in_the_sign_out_time_of_that_sign_in_only(): void
    {
        $student = $this->student();
        $otherDevice = LoginActivity::factory()->for($student)->create();
        $this->post(route('login.store'), ['login' => '20240001', 'password' => 'password']);
        $this->travel(10)->minutes();
        $this->freezeSecond();

        $this->post(route('logout'))->assertRedirect(route('landing'));

        $this->assertTrue(LoginActivity::latest('id')->first()->logged_out_at->eq(now()));
        $this->assertNull($otherDevice->fresh()->logged_out_at);
    }

    public function test_an_app_sign_in_is_recorded_as_mobile_with_the_device_from_the_user_agent(): void
    {
        $student = $this->student();

        $response = $this->withHeader('User-Agent', self::IPHONE_SAFARI)
            ->postJson(route('api.login'), ['login' => '20240001', 'password' => 'password', 'device_name' => "Clark's iPhone"])
            ->assertOk();

        $activity = LoginActivity::sole();
        $this->assertTrue($activity->user->is($student));
        $this->assertSame(LoginChannel::Api, $activity->channel);
        $this->assertSame('Safari on iPhone', $activity->device);
        $this->assertSame($student->tokens()->sole()->id, $activity->personal_access_token_id);

        // Only the token's id is kept, never the token itself.
        $this->assertStringNotContainsString(explode('|', $response->json('token'))[1], json_encode($activity->getAttributes()));
    }

    public function test_an_app_whose_user_agent_is_not_a_browser_is_recorded_with_the_device_name_it_sent(): void
    {
        $this->student();

        $this->withHeader('User-Agent', 'okhttp/4.12.0')
            ->postJson(route('api.login'), ['login' => '20240001', 'password' => 'password', 'device_name' => 'Pixel 8'])
            ->assertOk();

        $this->assertSame('Pixel 8', LoginActivity::sole()->device);
    }

    public function test_app_sign_out_fills_in_the_sign_out_time_of_that_token_only(): void
    {
        $this->student();
        $first = $this->postJson(route('api.login'), ['login' => '20240001', 'password' => 'password', 'device_name' => 'Phone'])->json('token');
        $this->postJson(route('api.login'), ['login' => '20240001', 'password' => 'password', 'device_name' => 'Tablet']);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$first}")->postJson(route('api.logout'))->assertNoContent();

        $this->assertNotNull(LoginActivity::where('device', 'Phone')->sole()->logged_out_at);
        $this->assertNull(LoginActivity::where('device', 'Tablet')->sole()->logged_out_at);
    }

    public function test_sign_ins_older_than_90_days_are_pruned(): void
    {
        $old = LoginActivity::factory()->create(['logged_in_at' => now()->subDays(91)]);
        $recent = LoginActivity::factory()->create(['logged_in_at' => now()->subDays(89)]);

        $this->artisan('model:prune', ['--model' => [LoginActivity::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
