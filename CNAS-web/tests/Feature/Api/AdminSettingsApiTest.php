<?php

namespace Tests\Feature\Api;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The admin's Settings in the mobile app (/api/admin/academic-terms, contact-details, emergency-contacts,
 * system-status), with the web's requests and messages.
 */
class AdminSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    #[TestWith([UserRole::Student])]
    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    public function test_only_the_admin_gets_in(UserRole $role): void
    {
        $contact = EmergencyContact::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson(route('api.admin.settings.terms'))->assertForbidden();
        $this->postJson(route('api.admin.settings.terms.store'), ['academic_year' => '2027-2028', 'semester' => '1st'])->assertForbidden();
        $this->putJson(route('api.admin.settings.contact.update'), [])->assertForbidden();
        $this->deleteJson(route('api.admin.settings.emergency-contacts.destroy', $contact))->assertForbidden();
        $this->getJson(route('api.admin.settings.status'))->assertForbidden();
        $this->assertModelExists($contact);
    }

    public function test_terms_list_the_current_one_and_warn_when_registration_is_closed(): void
    {
        $this->admin();
        AcademicTerm::factory()->term('2026-2027', Semester::First)->create();

        $this->getJson(route('api.admin.settings.terms'))
            ->assertOk()
            ->assertJsonPath('current', null)
            ->assertJsonPath('terms.0.label', 'AY 2026-2027, 1st Semester')
            ->assertJsonPath('warning.kind', 'closed')
            ->assertJsonPath('warning.message', 'Registration is closed: no academic term is set as current.');
    }

    public function test_a_term_is_added_and_set_as_current_with_the_web_messages(): void
    {
        $this->admin();

        $this->postJson(route('api.admin.settings.terms.store'), ['academic_year' => '2027 - 2028', 'semester' => '2nd'])
            ->assertCreated()
            ->assertJsonPath('message', "AY 2027-2028, 2nd Semester was added. It isn't current until you set it.");
        $term = AcademicTerm::sole();

        $this->postJson(route('api.admin.settings.terms.current', $term))
            ->assertOk()
            ->assertJsonPath('message', 'AY 2027-2028, 2nd Semester is now the current term.')
            ->assertJsonPath('data.is_current', true);
        $this->getJson(route('api.admin.settings.terms'))->assertJsonPath('warning', null)->assertJsonPath('current.id', $term->id);

        $this->postJson(route('api.admin.settings.terms.store'), ['academic_year' => '2027-2028', 'semester' => '2nd'])
            ->assertJsonValidationErrors(['academic_year' => 'That term already exists.']);
    }

    public function test_the_term_warning_comes_after_five_months(): void
    {
        $this->admin();
        AcademicTerm::factory()->term('2026-2027', Semester::First)->create()->makeCurrent();
        $this->travel(6)->months();

        $this->getJson(route('api.admin.settings.terms'))->assertJsonPath('warning.kind', 'outdated');
    }

    public function test_contact_details_are_saved_with_the_web_rules(): void
    {
        $this->admin();
        $details = ['email' => ' Office@TCC.edu.ph ', 'phone' => '0917 123 4567', 'facebook' => 'https://facebook.com/tcc', 'address' => 'Tagoloan, Misamis Oriental'];

        $this->putJson(route('api.admin.settings.contact.update'), $details)
            ->assertOk()
            ->assertJsonPath('message', 'Contact details saved. The site footer shows them now.')
            ->assertJsonPath('data.email', 'office@tcc.edu.ph');
        $this->getJson(route('api.admin.settings.contact'))->assertJsonPath('data.phone', '0917 123 4567');

        $this->putJson(route('api.admin.settings.contact.update'), [...$details, 'facebook' => 'http://facebook.com/tcc'])
            ->assertJsonValidationErrors(['facebook' => 'Enter the full page address starting with https://.']);
    }

    public function test_emergency_contacts_are_added_edited_moved_shown_and_deleted(): void
    {
        $this->admin();

        $this->postJson(route('api.admin.settings.emergency-contacts.store'), ['name' => 'Campus security', 'phone' => '0917 123 4567', 'note' => 'Main gate', 'is_visible' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'Campus security was added.');
        $this->postJson(route('api.admin.settings.emergency-contacts.store'), ['name' => 'Clinic', 'is_visible' => true])
            ->assertJsonValidationErrors(['phone' => 'Add a number before showing this contact to students.']);
        $this->postJson(route('api.admin.settings.emergency-contacts.store'), ['name' => 'Clinic', 'is_visible' => false])->assertCreated();
        [$security, $clinic] = EmergencyContact::ordered()->all();

        $this->patchJson(route('api.admin.settings.emergency-contacts.move', $clinic), ['direction' => 'up'])
            ->assertOk()
            ->assertJsonPath('message', 'Clinic was moved up.');
        $this->getJson(route('api.admin.settings.emergency-contacts'))->assertJsonPath('data.*.name', ['Clinic', 'Campus security']);

        $this->patchJson(route('api.admin.settings.emergency-contacts.visibility', $clinic), ['is_visible' => true])
            ->assertJsonValidationErrors(['is_visible' => 'Clinic: add a number before showing it to students.']);
        $this->putJson(route('api.admin.settings.emergency-contacts.update', $clinic), ['name' => 'School clinic', 'phone' => '0917 765 4321', 'is_visible' => true])
            ->assertOk()
            ->assertJsonPath('data.is_visible', true);
        $this->patchJson(route('api.admin.settings.emergency-contacts.visibility', $security), ['is_visible' => false])
            ->assertJsonPath('message', 'Campus security is now hidden from students.');

        // Students see the change at once.
        $this->getJson(route('api.emergency-contacts'))->assertJsonPath('data.*.name', ['School clinic']);

        $this->deleteJson(route('api.admin.settings.emergency-contacts.destroy', $security))->assertOk()->assertJsonPath('message', 'Campus security was deleted.');
        $this->assertModelMissing($security);
    }

    public function test_system_status_has_the_web_checks(): void
    {
        $this->admin();

        $response = $this->getJson(route('api.admin.settings.status'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['push-keys', 'expo-push', 'https', 'academic-term', 'map-data', 'push-subscribers'], $response->json('data.*.key'));
        $term = collect($response->json('data'))->firstWhere('key', 'academic-term');
        $this->assertFalse($term['ok']);
        $this->assertNotNull($term['fix']);
    }
}
