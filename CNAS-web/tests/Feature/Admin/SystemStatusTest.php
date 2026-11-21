<?php

namespace Tests\Feature\Admin;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\User;
use Database\Seeders\CampusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use NotificationChannels\WebPush\PushSubscription;
use Tests\TestCase;

/**
 * Settings → System status: each check in both states, and the live push subscriber count the tab polls.
 */
class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webpush.vapid' => [
            'public_key' => 'test-public-key',
            'private_key' => 'test-private-key-never-shown',
            'subject' => 'mailto:admin@tcc.edu.ph',
            'pem_file' => null,
        ]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function settingsPage(string $scheme = 'http'): TestResponse
    {
        return $this->actingAs($this->admin())
            ->get(preg_replace('#^https?://#', "{$scheme}://", route('admin.settings', ['tab' => 'system-status'])))
            ->assertOk();
    }

    private function assertCheck(TestResponse $page, string $name, bool $ok, string ...$lines): void
    {
        $page->assertSeeInOrder(['<section id="system-status"', $name, $ok ? 'OK' : 'Needs attention', ...$lines], false);
    }

    private function subscribe(string $endpoint, ?User $user = null): PushSubscription
    {
        return $user
            ? $user->updatePushSubscription($endpoint, 'key', 'auth', 'aes128gcm')
            : PushSubscription::create(['endpoint' => $endpoint, 'public_key' => 'key', 'auth_token' => 'auth', 'content_encoding' => 'aes128gcm']);
    }

    public function test_the_queue_worker_and_test_notification_are_not_on_the_page(): void
    {
        $this->settingsPage()
            ->assertDontSee('Queue worker')
            ->assertDontSee('Check now')
            ->assertDontSee('Test notification')
            ->assertDontSee('Send a test notification to my devices')
            ->assertSee('Emergency alerts on this device');
    }

    public function test_the_push_keys_are_ok_when_all_three_are_set_and_never_shown(): void
    {
        $page = $this->settingsPage();

        $this->assertCheck($page, 'Push keys', true, 'VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT are set.');
        $page->assertDontSee('test-private-key-never-shown')->assertDontSee('mailto:admin@tcc.edu.ph');
    }

    public function test_the_push_keys_need_attention_when_one_is_missing(): void
    {
        config(['webpush.vapid.subject' => null]);

        $this->assertCheck($this->settingsPage(), 'Push keys', false, 'Not set in .env: VAPID_SUBJECT.',
            'Set VAPID_SUBJECT in .env to “mailto:” plus the admin email or to the https site address, then run “php artisan config:clear”.');
    }

    public function test_https_is_ok_over_https(): void
    {
        $this->assertCheck($this->settingsPage('https'), 'HTTPS', true, 'This page was served over https, so push alerts and phone sensors can work.');
    }

    public function test_https_needs_attention_over_plain_http(): void
    {
        $this->assertCheck($this->settingsPage('http'), 'HTTPS', false, 'This page was served over plain http, so browsers block push alerts and phone sensors.',
            'Open the site through its https address (for example the ngrok tunnel) and set APP_URL in .env to it.');
    }

    public function test_the_academic_term_is_ok_when_one_is_current(): void
    {
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();

        $this->assertCheck($this->settingsPage(), 'Academic term', true, 'The current term is AY 2026-2027, 1st Semester.');
    }

    public function test_the_academic_term_needs_attention_without_a_current_term(): void
    {
        $this->assertCheck($this->settingsPage(), 'Academic term', false, 'No current term is set, so registration is closed.',
            'Add or choose the current term on the Academic term page.');
    }

    public function test_the_map_data_is_ok_with_buildings_paths_and_rooms(): void
    {
        $this->seed(CampusMapSeeder::class);

        $page = $this->settingsPage();

        $page->assertSeeInOrder(['Map data', 'OK', 'The map has ']);
        $this->assertMatchesRegularExpression('/The map has \d+ buildings, \d+ paths and \d+ rooms\./', $page->getContent());
    }

    public function test_the_map_data_needs_attention_when_the_map_is_empty(): void
    {
        $this->assertCheck($this->settingsPage(), 'Map data', false, 'The map has 0 buildings, 0 paths and 0 rooms, so directions can\'t work everywhere.',
            'Import campus.geojson on the Map data page.');
    }

    public function test_push_subscribers_are_counted_and_marked_live(): void
    {
        $this->subscribe('https://fcm.googleapis.com/fcm/send/guest');

        $page = $this->settingsPage();

        $this->assertCheck($page, 'Push subscribers', true, '1 device has emergency alerts turned on.');
        $page->assertSee('data-push-subscribers-live data-url="'.route('admin.settings.push-subscribers').'"', false);
    }

    public function test_push_subscribers_need_attention_when_no_device_has_alerts_on(): void
    {
        $this->assertCheck($this->settingsPage(), 'Push subscribers', false, 'No device has emergency alerts turned on yet.',
            'Ask students to tap “Turn on emergency alerts” on their portal home or the landing page.');
    }

    public function test_the_live_count_follows_devices_turning_alerts_on_and_off(): void
    {
        $this->freezeSecond();
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(route('admin.settings.push-subscribers'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'ok' => false,
                'summary' => 'No device has emergency alerts turned on yet.',
                'fix' => 'Ask students to tap “Turn on emergency alerts” on their portal home or the landing page.',
            ]);

        $student = $this->subscribe('https://fcm.googleapis.com/fcm/send/student', User::factory()->create());
        $this->subscribe('https://fcm.googleapis.com/fcm/send/guest');

        $this->actingAs($admin)->getJson(route('admin.settings.push-subscribers'))
            ->assertExactJson([
                'ok' => true,
                'summary' => '2 devices have emergency alerts turned on.',
                'fix' => null,
                'checked_at' => now()->toIso8601String(),
            ]);

        $student->delete(); // The student turned alerts off.

        $this->actingAs($admin)->getJson(route('admin.settings.push-subscribers'))
            ->assertJsonPath('summary', '1 device has emergency alerts turned on.');
    }

    public function test_the_live_count_is_admin_only(): void
    {
        $this->getJson(route('admin.settings.push-subscribers'))->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->getJson(route('admin.settings.push-subscribers'))
            ->assertForbidden();
    }
}
