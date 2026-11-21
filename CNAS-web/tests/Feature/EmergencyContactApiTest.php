<?php

namespace Tests\Feature;

use App\Models\EmergencyContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/emergency-contacts: the portal home's emergency numbers, for the mobile app. Public.
 */
class EmergencyContactApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_get_the_visible_contacts_in_order(): void
    {
        $security = EmergencyContact::factory()->create(['name' => 'Campus security', 'phone' => '+63 917-555-0101', 'note' => '24 hours']);
        $guidance = EmergencyContact::factory()->create(['name' => 'Guidance office', 'phone' => '088 555 0202']);
        EmergencyContact::factory()->hidden()->create();
        EmergencyContact::factory()->withoutPhone()->create();
        $guidance->move('up');

        $this->getJson(route('api.emergency-contacts'))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $guidance->id, 'name' => 'Guidance office', 'phone' => '088 555 0202', 'tel' => 'tel:0885550202', 'note' => null],
                ['id' => $security->id, 'name' => 'Campus security', 'phone' => '+63 917-555-0101', 'tel' => 'tel:+639175550101', 'note' => '24 hours'],
            ]]);
    }

    public function test_the_response_can_be_cached_by_the_app(): void
    {
        EmergencyContact::factory()->create();

        $response = $this->getJson(route('api.emergency-contacts'))->assertOk();

        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->getJson(route('api.emergency-contacts'), ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_a_change_shows_up_on_the_next_request(): void
    {
        $security = EmergencyContact::factory()->create(['name' => 'Campus security']);
        $this->getJson(route('api.emergency-contacts'))->assertJsonCount(1, 'data'); // Cached now.

        $security->update(['phone' => '911']);

        $this->getJson(route('api.emergency-contacts'))->assertJsonPath('data.0.phone', '911');
    }
}
