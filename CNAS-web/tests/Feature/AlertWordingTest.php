<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Short wording at the source: a title of at most 60 characters, and "What to do now" (one sentence, at most 100,
 * required for an emergency) as the bold action line; older alerts fall back to their instructions. Plus the app's
 * public list of recent alerts.
 */
class AlertWordingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function alertInput(array $overrides = []): array
    {
        return [
            'type' => 'fire',
            'severity' => 'emergency',
            'title' => 'Fire on campus',
            'message' => 'A fire has been reported.',
            'what_to_do' => 'Leave the building now. Go to the open field.',
            ...$overrides,
        ];
    }

    public function test_an_emergency_needs_a_short_what_to_do_and_a_short_title(): void
    {
        Queue::fake();
        $this->actingAs($this->admin());

        $this->post(route('admin.alerts.store'), $this->alertInput(['what_to_do' => '']))
            ->assertSessionHasErrors(['what_to_do' => 'Say in one short sentence what people must do now.']);
        $this->post(route('admin.alerts.store'), $this->alertInput(['what_to_do' => str_repeat('a', Alert::WHAT_TO_DO_MAX + 1)]))
            ->assertSessionHasErrors('what_to_do');
        $this->post(route('admin.alerts.store'), $this->alertInput(['title' => str_repeat('a', Alert::TITLE_MAX + 1)]))
            ->assertSessionHasErrors(['title' => 'Keep the title to 60 characters so it fits on a phone.']);
        $this->assertDatabaseEmpty('alerts');

        $this->post(route('admin.alerts.store'), $this->alertInput(['title' => str_repeat('a', Alert::TITLE_MAX)]))->assertSessionHasNoErrors();
        $this->assertSame('Leave the building now. Go to the open field.', Alert::sole()->what_to_do);
    }

    #[TestWith(['warning'])]
    #[TestWith(['advisory'])]
    public function test_warnings_and_advisories_may_leave_what_to_do_empty(string $severity): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.alerts.store'), $this->alertInput(['type' => 'flood', 'severity' => $severity, 'what_to_do' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Alert::sole()->what_to_do);
    }

    public function test_every_template_fits_the_limits_and_an_emergency_template_says_what_to_do(): void
    {
        foreach (AlertType::cases() as $type) {
            $template = $type->template();
            $this->assertLessThanOrEqual(Alert::TITLE_MAX, mb_strlen($template['title']), $type->value);
            $this->assertLessThanOrEqual(Alert::WHAT_TO_DO_MAX, mb_strlen($template['what_to_do']), $type->value);
            if ($type->defaultSeverity()->isEmergency()) {
                $this->assertNotSame('', $template['what_to_do'], $type->value);
            }
        }
    }

    public function test_an_older_alert_falls_back_to_the_first_sentence_of_its_instructions(): void
    {
        $alert = Alert::factory()->create([
            'what_to_do' => null,
            'instructions' => "Leave the building now by the nearest exit. Stay low if there is smoke.\nDo not go back inside.",
        ]);

        $this->assertSame('Leave the building now by the nearest exit.', $alert->whatToDo());
        $this->getJson(route('api.alerts.active'))->assertJsonPath('data.what_to_do', 'Leave the building now by the nearest exit.');

        $alert->update(['instructions' => null]);
        $this->assertSame('', $alert->whatToDo());
    }

    public function test_a_long_first_sentence_is_cut_to_the_limit(): void
    {
        $alert = Alert::factory()->create(['what_to_do' => null, 'instructions' => str_repeat('word ', 40)]);

        $this->assertLessThanOrEqual(Alert::WHAT_TO_DO_MAX, mb_strlen($alert->whatToDo()));
    }

    public function test_anyone_gets_the_alerts_of_the_last_30_days_newest_first(): void
    {
        $old = Alert::factory()->resolved()->create(['sent_at' => now()->subDays(31), 'title' => 'Old drill']);
        $earlier = Alert::factory()->resolved()->create(['sent_at' => now()->subDays(3), 'title' => 'Typhoon']);
        $latest = Alert::factory()->create(['sent_at' => now()->subMinute()]);

        $response = $this->getJson(route('api.alerts.recent'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.0.what_to_do', 'Leave the building now. Go to the open field.')
            ->assertJsonPath('data.1.id', $earlier->id)
            ->assertJsonCount(2, 'data');

        $this->assertStringNotContainsString($old->title, $response->getContent());
        $this->assertArrayNotHasKey('created_by', $response->json('data.0'));
    }
}
