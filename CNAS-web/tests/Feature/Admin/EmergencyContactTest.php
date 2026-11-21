<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\EmergencyContact;
use App\Models\User;
use Database\Seeders\EmergencyContactSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings → Emergency contacts (add, edit, delete, order, show/hide) and the list on the portal home.
 */
class EmergencyContactTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => UserRole::Student]);
    }

    /**
     * @return list<string>
     */
    private function namesInOrder(): array
    {
        return EmergencyContact::ordered()->pluck('name')->all();
    }

    public function test_an_admin_adds_a_contact_at_the_end_of_the_list(): void
    {
        EmergencyContact::factory()->create(['name' => 'Campus security']);

        $this->actingAs($this->admin())
            ->post(route('admin.settings.emergency-contacts.store'), [
                'name' => 'School clinic', 'phone' => '+63 917-555-0101', 'note' => '24 hours', 'is_visible' => '1',
            ])
            ->assertRedirect(SettingsController::tabUrl('emergency-contacts'))
            ->assertSessionHas('section', 'emergency')
            ->assertSessionHas('status', 'School clinic was added.');

        $this->assertSame(['Campus security', 'School clinic'], $this->namesInOrder());
        $clinic = EmergencyContact::firstWhere('name', 'School clinic');
        $this->assertSame(['+63 917-555-0101', '24 hours', true], [$clinic->phone, $clinic->note, $clinic->is_visible]);
    }

    public function test_a_contact_needs_a_name(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.emergency-contacts.store'), ['name' => '', 'phone' => '911'])
            ->assertSessionHasErrors(['name' => 'Enter who students are calling, like Campus security.'], errorBag: 'newEmergencyContact');

        $this->assertDatabaseCount('emergency_contacts', 0);
    }

    public function test_a_contact_without_a_number_cannot_be_shown(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.emergency-contacts.store'), ['name' => 'School clinic', 'phone' => '', 'is_visible' => '1'])
            ->assertSessionHasErrors(['phone' => 'Add a number before showing this contact to students.'], errorBag: 'newEmergencyContact');

        $this->assertDatabaseCount('emergency_contacts', 0);
    }

    public function test_a_hidden_contact_can_be_saved_without_a_number(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.emergency-contacts.store'), ['name' => 'School clinic', 'phone' => '', 'is_visible' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertNull(EmergencyContact::sole()->phone);
    }

    public function test_the_phone_must_be_digits_spaces_and_dashes(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.emergency-contacts.store'), ['name' => 'School clinic', 'phone' => 'ext. 12'])
            ->assertSessionHasErrors(['phone' => 'Use digits, spaces and dashes only, with an optional + in front (e.g. +63 912-345-6789).'], errorBag: 'newEmergencyContact');
    }

    public function test_an_admin_edits_a_contact_and_errors_stay_on_its_own_form(): void
    {
        $admin = $this->admin();
        $clinic = EmergencyContact::factory()->withoutPhone()->create(['name' => 'School clinic']);

        $this->actingAs($admin)
            ->put(route('admin.settings.emergency-contacts.update', $clinic), ['name' => 'School clinic', 'phone' => 'abc', 'is_visible' => '0'])
            ->assertSessionHasErrors(['phone'], errorBag: "emergencyContact{$clinic->id}");

        $this->actingAs($admin)
            ->put(route('admin.settings.emergency-contacts.update', $clinic), [
                'name' => 'TCC clinic', 'phone' => '088 555 0101', 'note' => 'Mon–Fri', 'is_visible' => '1',
            ])
            ->assertRedirect(SettingsController::tabUrl('emergency-contacts'))
            ->assertSessionHas('status', 'TCC clinic was saved.');

        $clinic->refresh();
        $this->assertSame(['TCC clinic', '088 555 0101', 'Mon–Fri', true], [$clinic->name, $clinic->phone, $clinic->note, $clinic->is_visible]);
    }

    public function test_an_admin_deletes_a_contact(): void
    {
        $clinic = EmergencyContact::factory()->create(['name' => 'School clinic']);

        $this->actingAs($this->admin())
            ->delete(route('admin.settings.emergency-contacts.destroy', $clinic))
            ->assertRedirect(SettingsController::tabUrl('emergency-contacts'))
            ->assertSessionHas('status', 'School clinic was deleted.');

        $this->assertModelMissing($clinic);
    }

    public function test_an_admin_moves_contacts_up_and_down(): void
    {
        $admin = $this->admin();
        EmergencyContact::factory()->create(['name' => 'Campus security']);
        $clinic = EmergencyContact::factory()->create(['name' => 'School clinic']);
        $guidance = EmergencyContact::factory()->create(['name' => 'Guidance office']);

        $this->actingAs($admin)
            ->patch(route('admin.settings.emergency-contacts.move', $guidance), ['direction' => 'up'])
            ->assertRedirect(SettingsController::tabUrl('emergency-contacts'));
        $this->assertSame(['Campus security', 'Guidance office', 'School clinic'], $this->namesInOrder());

        $this->actingAs($admin)->patch(route('admin.settings.emergency-contacts.move', $clinic), ['direction' => 'down']);
        $this->assertSame(['Campus security', 'Guidance office', 'School clinic'], $this->namesInOrder(), 'The last one stays last.');

        $this->actingAs($admin)
            ->patch(route('admin.settings.emergency-contacts.move', $guidance), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');
    }

    public function test_an_admin_hides_and_shows_a_contact(): void
    {
        $admin = $this->admin();
        $clinic = EmergencyContact::factory()->create(['name' => 'School clinic']);

        $this->actingAs($admin)
            ->patch(route('admin.settings.emergency-contacts.visibility', $clinic), ['is_visible' => '0'])
            ->assertSessionHas('status', 'School clinic is now hidden from students.');
        $this->assertFalse($clinic->fresh()->is_visible);

        $this->actingAs($admin)
            ->patch(route('admin.settings.emergency-contacts.visibility', $clinic), ['is_visible' => '1'])
            ->assertSessionHas('status', 'School clinic is now shown to students.');
        $this->assertTrue($clinic->fresh()->is_visible);
    }

    public function test_a_contact_without_a_number_cannot_be_switched_on(): void
    {
        $clinic = EmergencyContact::factory()->withoutPhone()->create(['name' => 'School clinic']);

        $this->actingAs($this->admin())
            ->patch(route('admin.settings.emergency-contacts.visibility', $clinic), ['is_visible' => '1'])
            ->assertRedirect(SettingsController::tabUrl('emergency-contacts'))
            ->assertSessionHasErrors(['is_visible' => 'School clinic: add a number before showing it to students.'], errorBag: 'emergencyContacts');

        $this->assertFalse($clinic->fresh()->is_visible);
    }

    public function test_the_settings_page_lists_every_contact_with_its_state(): void
    {
        EmergencyContact::factory()->create(['name' => 'Campus security', 'phone' => '+63 917-555-0101']);
        EmergencyContact::factory()->withoutPhone()->create(['name' => 'School clinic']);

        $this->actingAs($this->admin())->get(route('admin.settings'))
            ->assertOk()
            ->assertSeeInOrder(['Campus security', '+63 917-555-0101', 'Shown', 'School clinic', 'No number yet', 'Hidden']);
    }

    public function test_the_portal_home_lists_visible_contacts_in_order_with_tap_to_call_links(): void
    {
        $security = EmergencyContact::factory()->create(['name' => 'Campus security', 'phone' => '+63 917-555-0101', 'note' => '24 hours']);
        EmergencyContact::factory()->create(['name' => 'Guidance office', 'phone' => '088 555 0202']);
        EmergencyContact::factory()->hidden()->create(['name' => 'Hidden hotline']);
        EmergencyContact::factory()->withoutPhone()->create(['name' => 'School clinic']);
        $security->move('down');

        $this->actingAs($this->student())->get(route('student.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Emergency contacts', 'Guidance office', 'href="tel:0885550202"', 'Campus security', '24 hours', 'href="tel:+639175550101"'], false)
            ->assertSee('aria-label="Call Campus security, +63 917-555-0101"', false)
            ->assertDontSee('Hidden hotline')
            ->assertDontSee('School clinic')
            ->assertDontSee('Number to be added');
    }

    public function test_the_portal_home_hides_the_block_without_visible_contacts(): void
    {
        EmergencyContact::factory()->withoutPhone()->create(['name' => 'Campus security']);

        $this->actingAs($this->student())->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Emergency contacts')
            ->assertDontSee('Campus security');
    }

    public function test_the_portal_list_updates_after_the_admin_changes_a_contact(): void
    {
        $student = $this->student();
        $security = EmergencyContact::factory()->create(['name' => 'Campus security']);
        $this->actingAs($student)->get(route('student.dashboard'))->assertSee('Campus security'); // Cached now.

        $this->actingAs($this->admin())->patch(route('admin.settings.emergency-contacts.visibility', $security), ['is_visible' => '0']);
        $this->flushSession(); // The student has their own session: the admin's "saved" toast isn't theirs.

        $this->actingAs($student)->get(route('student.dashboard'))->assertDontSee('Campus security');
    }

    public function test_the_seeder_adds_the_three_placeholders_hidden_and_keeps_later_edits(): void
    {
        $this->seed(EmergencyContactSeeder::class);

        $this->assertSame(['Campus security', 'School clinic', 'Guidance office'], $this->namesInOrder());
        $this->assertSame(0, EmergencyContact::whereNotNull('phone')->orWhere('is_visible', true)->count());

        EmergencyContact::firstWhere('name', 'School clinic')->update(['phone' => '911', 'is_visible' => true]);
        $this->seed(EmergencyContactSeeder::class);

        $this->assertSame(3, EmergencyContact::count());
        $this->assertSame(['911', true], [EmergencyContact::firstWhere('name', 'School clinic')->phone, EmergencyContact::firstWhere('name', 'School clinic')->is_visible]);
    }
}
