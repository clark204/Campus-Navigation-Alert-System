<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The Settings tabs: the server opens one tab (?tab=, Contact details by default) and hides the other panels, so the
 * page also works without JavaScript and a save comes back to its own tab.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $query = []): TestResponse
    {
        return $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.settings', $query))
            ->assertOk();
    }

    private function assertOnlyOpenTab(TestResponse $page, string $open): void
    {
        foreach (array_keys(SettingsController::TABS) as $tab) {
            $isOpen = $tab === $open;
            $this->assertMatchesRegularExpression(
                '#id="tab-'.$tab.'" role="tab" href="'.preg_quote(route('admin.settings', ['tab' => $tab]), '#').'" aria-controls="'.$tab.'"\s+aria-selected="'.($isOpen ? 'true' : 'false').'"#',
                $page->getContent(),
            );
            $this->assertSame(1, preg_match('#<section id="'.$tab.'" role="tabpanel" aria-labelledby="tab-'.$tab.'"([^>]*)>#', $page->getContent(), $panel));
            $this->assertSame(! $isOpen, (bool) preg_match('#\shidden\s#', $panel[1].' '), "Panel {$tab} should be ".($isOpen ? 'shown' : 'hidden').'.');
        }
    }

    public function test_contact_details_is_the_default_tab(): void
    {
        $page = $this->settings();

        $this->assertOnlyOpenTab($page, 'contact-details');
        $page->assertSeeInOrder(['role="tablist"', 'Contact details', 'Emergency contacts', 'My account', 'System status'], false);
    }

    #[TestWith(['emergency-contacts'])]
    #[TestWith(['my-account'])]
    #[TestWith(['system-status'])]
    public function test_the_tab_in_the_address_is_opened(string $tab): void
    {
        $this->assertOnlyOpenTab($this->settings(['tab' => $tab]), $tab);
    }

    public function test_an_unknown_tab_opens_the_default(): void
    {
        $this->assertOnlyOpenTab($this->settings(['tab' => 'queue-worker']), 'contact-details');
    }
}
