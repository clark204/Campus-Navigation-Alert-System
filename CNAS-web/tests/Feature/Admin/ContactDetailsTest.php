<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Settings → Contact details: the admin edits the contact shown in the site footer and the portal Help & contact page.
 */
class ContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{email: string, phone: string, facebook: string, address: string} */
    private const VALID = [
        'email' => 'cnas-help@tcc.edu.ph',
        'phone' => '+63 917-555-0101',
        'facebook' => 'https://www.facebook.com/tcc-cnas',
        'address' => 'Poblacion, Tagoloan, Misamis Oriental',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cnas.contact' => [
            'email' => 'config@test.example',
            'phone' => '+63 900-111-222',
            'facebook' => 'https://www.facebook.com/config-page',
            'address' => 'Config address',
        ]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_the_form_shows_the_config_values_until_something_is_saved(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings'))
            ->assertOk()
            ->assertSeeInOrder(['Contact details', 'value="config@test.example"', 'value="+63 900-111-222"', 'value="https://www.facebook.com/config-page"', 'value="Config address"'], false);
    }

    public function test_saving_updates_the_site_footer_right_away(): void
    {
        $this->get(route('landing'))->assertSee('config@test.example'); // Fills the cache with the config values.

        $this->actingAs($this->admin())
            ->put(route('admin.settings.contact.update'), [...self::VALID, 'email' => '  CNAS-Help@TCC.edu.ph '])
            ->assertRedirect(SettingsController::tabUrl('contact-details'))
            ->assertSessionHas('section', 'contact')
            ->assertSessionHas('status', 'Contact details saved. The site footer shows them now.');

        $this->assertSame('cnas-help@tcc.edu.ph', Setting::find('contact.email')?->value);

        auth()->logout();
        $this->get(route('landing'))
            ->assertSee('href="mailto:cnas-help@tcc.edu.ph"', false)
            ->assertSee('href="tel:+639175550101"', false)
            ->assertSee('+63 917-555-0101')
            ->assertSee('href="https://www.facebook.com/tcc-cnas"', false)
            ->assertSee('Poblacion, Tagoloan, Misamis Oriental')
            ->assertDontSee('config@test.example');
    }

    public function test_a_value_never_saved_falls_back_to_config(): void
    {
        Setting::create(['key' => 'contact.email', 'value' => 'saved@test.example']);

        $this->get(route('landing'))
            ->assertSee('href="mailto:saved@test.example"', false)
            ->assertSee('+63 900-111-222')
            ->assertSee('Config address');
    }

    public function test_every_field_is_required(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.contact.update'), [])
            ->assertRedirect(SettingsController::tabUrl('contact-details'))
            ->assertSessionHasErrors([
                'email' => 'Enter the email students can write to.',
                'phone' => 'Enter the phone number students can call.',
                'facebook' => 'Enter the Facebook page address.',
                'address' => 'Enter the address.',
            ], errorBag: 'contact');

        $this->assertDatabaseCount('settings', 0);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'email without a domain' => ['email', 'help@localhost', 'Enter a real email address, like name@example.com.'],
            'email with a space' => ['email', 'help desk@tcc.edu.ph', 'Enter a real email address, like name@example.com.'],
            'phone with letters' => ['phone', 'call 0917', 'Use digits, spaces and dashes only, with an optional + in front (e.g. +63 912-345-6789).'],
            'phone with brackets' => ['phone', '(088) 555-0101', 'Use digits, spaces and dashes only, with an optional + in front (e.g. +63 912-345-6789).'],
            'phone with a + in the middle' => ['phone', '0917+555', 'Use digits, spaces and dashes only, with an optional + in front (e.g. +63 912-345-6789).'],
            'facebook over http' => ['facebook', 'http://www.facebook.com/tcc', 'Enter the full page address starting with https://.'],
            'facebook not a url' => ['facebook', 'facebook.com/tcc', 'Enter the full page address starting with https://.'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_refused_with_a_message(string $field, string $value, string $message): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.contact.update'), [...self::VALID, $field => $value])
            ->assertSessionHasErrors([$field => $message], errorBag: 'contact');

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_an_error_keeps_the_typed_values_in_the_contact_form_only(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->put(route('admin.settings.contact.update'), [...self::VALID, 'phone' => 'not a phone'])
            ->assertRedirect();

        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertSee('value="not a phone"', false)
            ->assertSee('value="cnas-help@tcc.edu.ph"', false)
            ->assertSee('id="contact-phone-error"', false)
            // The account email field (same field name) still shows the admin's own email.
            ->assertSee('value="'.$admin->email.'"', false);
    }
}
