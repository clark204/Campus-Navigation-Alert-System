<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin.dashboard'])]
    #[TestWith(['admin.academic-terms.index'])]
    #[TestWith(['admin.settings'])]
    public function test_guests_are_sent_to_sign_in(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[TestWith(['admin.dashboard'])]
    #[TestWith(['admin.academic-terms.index'])]
    #[TestWith(['admin.settings'])]
    public function test_non_admins_are_forbidden(string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route($route))
            ->assertForbidden();
    }

    public function test_non_admins_cannot_change_terms(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))
            ->post(route('admin.academic-terms.store'), ['academic_year' => '2026-2027', 'semester' => '2nd'])
            ->assertForbidden();

        $this->assertDatabaseCount('academic_terms', 0);
    }

    /**
     * Every settings action, for a signed-in non-admin: [method, route name, needs an emergency contact].
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function settingsActions(): array
    {
        return [
            'save contact details' => ['put', 'admin.settings.contact.update', false],
            'add an emergency contact' => ['post', 'admin.settings.emergency-contacts.store', false],
            'edit an emergency contact' => ['put', 'admin.settings.emergency-contacts.update', true],
            'delete an emergency contact' => ['delete', 'admin.settings.emergency-contacts.destroy', true],
            'move an emergency contact' => ['patch', 'admin.settings.emergency-contacts.move', true],
            'show or hide an emergency contact' => ['patch', 'admin.settings.emergency-contacts.visibility', true],
            'change my email' => ['put', 'admin.settings.account.email', false],
            'change my password' => ['put', 'admin.settings.account.password', false],
        ];
    }

    #[DataProvider('settingsActions')]
    public function test_non_admins_cannot_use_settings(string $method, string $routeName, bool $needsContact): void
    {
        $contact = EmergencyContact::factory()->create(['name' => 'Campus security']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))
            ->{$method}(route($routeName, $needsContact ? $contact : []), [
                'name' => 'Changed', 'email' => 'changed@tcc.edu.ph', 'phone' => '911', 'facebook' => 'https://www.facebook.com/x',
                'address' => 'x', 'direction' => 'down', 'is_visible' => '0',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('settings', 0);
        $this->assertSame(['Campus security', true], [$contact->fresh()->name, $contact->fresh()->is_visible]);
    }

    public function test_admins_get_in(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Campus overview');
    }
}
