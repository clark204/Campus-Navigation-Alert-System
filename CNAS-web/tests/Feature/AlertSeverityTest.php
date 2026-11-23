<?php

namespace Tests\Feature;

use App\Enums\AlertLogAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Jobs\SendAlertPush;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\AlertPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Severity levels: advisory (a yellow banner), warning (an orange banner, a popup and a chime) and emergency (the
 * takeover, the alarm and "I'm safe"). Each level's rules for what people see, what they are asked and what is pushed.
 */
class AlertSeverityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * @return array<string, mixed>
     */
    private function alertInput(array $overrides = []): array
    {
        return [
            'type' => 'fire',
            'severity' => 'emergency',
            'title' => 'Fire on campus',
            'message' => 'A fire has been reported.',
            'what_to_do' => 'Leave the building now.',
            'instructions' => 'Leave the building.',
            ...$overrides,
        ];
    }

    #[TestWith(['fire', AlertSeverity::Emergency])]
    #[TestWith(['earthquake', AlertSeverity::Emergency])]
    #[TestWith(['security', AlertSeverity::Emergency])]
    #[TestWith(['flood', AlertSeverity::Warning])]
    #[TestWith(['typhoon', AlertSeverity::Warning])]
    #[TestWith(['other', AlertSeverity::Advisory])]
    public function test_each_type_has_its_default_level(string $type, AlertSeverity $level): void
    {
        $this->assertSame($level, AlertType::from($type)->defaultSeverity());
    }

    public function test_the_send_form_preselects_the_level_of_the_chosen_type(): void
    {
        $this->actingAs($this->admin())->get(route('admin.alerts.create', ['type' => 'flood']))
            ->assertOk()
            ->assertViewHas('severity', AlertSeverity::Warning)
            ->assertSee('data-default-severity="warning"', false);
    }

    public function test_an_alert_is_sent_with_the_chosen_level(): void
    {
        Queue::fake([SendAlertPush::class]);

        $this->actingAs($this->admin())
            ->post(route('admin.alerts.store'), $this->alertInput(['type' => 'other', 'severity' => 'advisory']))
            ->assertRedirect(route('admin.alerts.index'));

        $this->assertSame(AlertSeverity::Advisory, Alert::sole()->severity);
    }

    #[TestWith([[]])]
    #[TestWith([['severity' => 'panic']])]
    public function test_a_level_is_required_and_must_be_one_of_the_three(array $change): void
    {
        $input = $this->alertInput($change);
        unset($input['severity']);
        if (isset($change['severity'])) {
            $input['severity'] = $change['severity'];
        }

        $this->actingAs($this->admin())
            ->post(route('admin.alerts.store'), $input)
            ->assertSessionHasErrors(['severity' => 'Choose how serious it is.']);

        $this->assertSame(0, Alert::count());
    }

    public function test_changing_the_level_of_an_active_alert_is_logged_and_pushed_again(): void
    {
        $alert = Alert::factory()->advisory()->create();
        Queue::fake([SendAlertPush::class]);

        $this->actingAs($this->admin())
            ->put(route('admin.alerts.update', $alert), [
                'severity' => 'emergency',
                'title' => $alert->title,
                'message' => $alert->message,
                'what_to_do' => $alert->what_to_do,
                'instructions' => $alert->instructions,
            ])
            ->assertRedirect(route('admin.alerts.index'));

        $alert->refresh();
        $this->assertSame(AlertSeverity::Emergency, $alert->severity);
        $log = $alert->logs()->where('action', AlertLogAction::SeverityChanged)->sole();
        $this->assertSame('Advisory → Emergency', $log->detail);
        Queue::assertPushed(SendAlertPush::class, fn (SendAlertPush $job) => $job->alert->is($alert) && $job->resolution === null);
    }

    public function test_the_level_of_an_active_alert_can_only_be_changed_to_a_real_level(): void
    {
        $alert = Alert::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.alerts.update', $alert), ['severity' => 'panic', 'title' => 'x', 'message' => 'y'])
            ->assertSessionHasErrors(['severity' => 'Choose how serious it is.']);

        $this->assertSame(AlertSeverity::Emergency, $alert->refresh()->severity);
    }

    #[TestWith([AlertSeverity::Advisory])]
    #[TestWith([AlertSeverity::Warning])]
    public function test_only_an_emergency_asks_whether_you_are_safe(AlertSeverity $level): void
    {
        $alert = Alert::factory()->create(['severity' => $level]);
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->actingAs($student)->postJson(route('alerts.safe', $alert))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only an emergency asks whether you are safe.');

        $this->assertDatabaseEmpty('safety_checks');
    }

    public function test_the_safe_button_is_only_shown_during_an_emergency(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        Alert::factory()->warning()->create();
        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('data-alert-banner', false)
            ->assertSee('data-severity="warning"', false)
            ->assertDontSee('data-mark-safe-form', false);
    }

    public function test_the_banner_is_colored_by_level_and_offers_the_route_only_when_it_applies(): void
    {
        $advisory = Alert::factory()->advisory()->pinned()->create();
        $this->assertBannerRoute($advisory, hidden: true);

        $warningWithoutLocation = Alert::factory()->warning()->create(['building_codes' => [], 'latitude' => null, 'longitude' => null]);
        $this->assertBannerRoute($warningWithoutLocation, hidden: true);

        $warningWithLocation = Alert::factory()->warning()->pinned()->create();
        $this->assertBannerRoute($warningWithLocation, hidden: false);
    }

    private function assertBannerRoute(Alert $alert, bool $hidden): void
    {
        $html = view('partials.alert-banner', ['alert' => $alert, 'compactBanner' => false, 'canMarkSafe' => false])->render();

        $this->assertStringContainsString('data-severity="'.$alert->severity->value.'"', $html);
        $link = preg_match('/<a [^>]*data-route-to-safety[^>]*>/', $html, $match) ? $match[0] : '';
        $this->assertNotSame('', $link, 'the banner has a route link');
        $this->assertSame($hidden, str_contains($link, ' hidden'), "route link hidden for {$alert->severity->value}");
    }

    public function test_the_api_says_the_level_and_whether_the_alert_has_a_location(): void
    {
        Alert::factory()->warning()->pinned()->create();

        $this->getJson(route('api.alerts.active'))
            ->assertOk()
            ->assertJsonPath('data.severity', 'warning')
            ->assertJsonPath('data.severity_label', 'Warning')
            ->assertJsonPath('data.has_location', true);
    }

    #[TestWith([AlertSeverity::Advisory, 'ADVISORY · OTHER EMERGENCY: Fire on campus', false, false])]
    #[TestWith([AlertSeverity::Warning, 'WARNING · FLOOD: Fire on campus', false, false])]
    #[TestWith([AlertSeverity::Emergency, 'EMERGENCY · FIRE: Fire on campus', true, true])]
    public function test_the_push_says_the_level_and_only_an_emergency_waits_and_buzzes(AlertSeverity $level, string $title, bool $waits, bool $buzzes): void
    {
        $alert = Alert::factory()->create([
            'type' => $level === AlertSeverity::Advisory ? AlertType::Other : ($level === AlertSeverity::Warning ? AlertType::Flood : AlertType::Fire),
            'severity' => $level,
        ]);

        $message = (new AlertPushNotification($alert))->toWebPush(new AnonymousNotifiable, new class extends Notification {});
        $array = $message->toArray();

        $this->assertSame($title, $array['title']);
        $this->assertSame($waits, $array['requireInteraction'] ?? false);
        $this->assertSame($buzzes, array_key_exists('vibrate', $array));
    }

    public function test_each_level_says_when_to_choose_it_with_an_example(): void
    {
        $this->assertSame(
            ['Be aware. No danger right now.', 'Get ready. Danger may reach people soon.', 'Act now. People are in danger.'],
            array_map(fn (AlertSeverity $level) => $level->description(), AlertSeverity::cases()),
        );
        $this->assertSame(
            ['Heavy rain, power cut, a gate is closed.', 'Rising flood water, strong typhoon winds.', 'Fire, earthquake, security threat.'],
            array_map(fn (AlertSeverity $level) => $level->example(), AlertSeverity::cases()),
        );
    }

    public function test_each_level_says_what_students_get(): void
    {
        $this->assertSame(
            [
                'Students get: a yellow banner and a notification.',
                'Students get: an orange banner, a popup with a chime, and a notification.',
                "Students get: a full-screen alarm, the I'm safe button, the route to safety, and a notification.",
            ],
            array_map(fn (AlertSeverity $level) => $level->studentsGet(), AlertSeverity::cases()),
        );
    }

    public function test_the_send_form_shows_the_three_cards_and_what_students_get_for_the_chosen_level(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.alerts.create', ['type' => 'fire']))->assertOk();

        foreach (AlertSeverity::cases() as $level) {
            $response->assertSee('e.g. '.e($level->example()), false);
        }
        $this->assertLineFor($response->getContent(), AlertSeverity::Emergency);
        $response->assertSee('data-students-get="'.e(AlertSeverity::Advisory->studentsGet()).'"', false);
    }

    public function test_the_edit_form_shows_the_line_for_the_alerts_current_level(): void
    {
        $alert = Alert::factory()->advisory()->create();

        $response = $this->actingAs($this->admin())->get(route('admin.alerts.edit', $alert))->assertOk();

        $this->assertLineFor($response->getContent(), AlertSeverity::Advisory);
        $response->assertSee('Changing the level sends everyone a new notification.');
    }

    /**
     * The line under the cards (the one that changes with the selection), not the cards' own text.
     */
    private function assertLineFor(string $html, AlertSeverity $level): void
    {
        $pattern = '/<p data-students-get[^>]*>'.preg_quote(e($level->studentsGet()), '/').'<\/p>/';
        $this->assertMatchesRegularExpression($pattern, $html, "the line shows what students get for {$level->value}");
    }
}
