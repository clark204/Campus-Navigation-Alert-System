<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Room;
use App\Models\Stair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "QR code" button on Admin > Rooms and Admin > Buildings: a printable card holding
 * <APP_URL>/navigate?from=<place id> (a room's door, or one of a building's doors), or the reason it can't have one yet.
 */
class QrCodeTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://tcc-mapsafe.online';

    private Building $library;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE]);

        // The Library: two doors downstairs, stairs up to the 2nd floor only.
        $this->library = Building::factory()->create(['code' => 'b5', 'name' => 'Building 05', 'landmark_name' => 'the Library', 'floors' => 3]);
        Room::factory()->for($this->library)->create(['code' => 'r1', 'name' => 'Reading Room']);
        Room::factory()->for($this->library)->create(['code' => 'r2', 'name' => 'Archive', 'door' => 'Archive side door']);
        Room::factory()->for($this->library)->create(['code' => 'r3', 'name' => 'Study Hall', 'floor' => 2]);
        Room::factory()->for($this->library)->create(['code' => 'r4', 'name' => 'Roof Deck', 'floor' => 3]);
        Stair::factory()->for($this->library)->create(['floors' => 2]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function room(string $code): Room
    {
        return Room::query()->where('code', $code)->firstOrFail();
    }

    public function test_only_admins_can_open_a_qr_code(): void
    {
        $this->get(route('admin.rooms.qr', $this->room('r1')))->assertRedirect(route('login'));

        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->actingAs($student)->get(route('admin.rooms.qr', $this->room('r1')))->assertForbidden();
        $this->actingAs($student)->get(route('admin.buildings.qr', $this->library))->assertForbidden();
    }

    public function test_the_rooms_and_buildings_lists_have_a_qr_code_button_beside_edit(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.rooms.index'))
            ->assertOk()
            ->assertSeeInOrder([route('admin.rooms.qr', $this->room('r1')), 'QR code', route('admin.rooms.edit', $this->room('r1'))], false);
        $this->actingAs($admin)->get(route('admin.buildings.index'))
            ->assertOk()
            ->assertSeeInOrder([route('admin.buildings.qr', $this->library), 'QR code', route('admin.buildings.edit', $this->library)], false);
    }

    public function test_a_room_card_has_its_code_name_floor_and_navigate_link(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.rooms.qr', $this->room('r3')));

        $response->assertOk()
            ->assertSeeInOrder(['QR code for Study Hall', 'Study Hall', 'Library · 2nd floor', 'Scan to start directions from here', self::SITE.'/navigate?from=r3'])
            ->assertSee('Back to Rooms')
            ->assertSee(asset('logo/tcc-seal.png'))
            ->assertDontSee('Not ready for posting yet.');
        $this->assertSame(1, substr_count($response->getContent(), '<svg xmlns="http://www.w3.org/2000/svg" class="qr-svg'));
    }

    public function test_an_upstairs_room_the_stairs_dont_reach_says_why_instead_of_a_code(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.rooms.qr', $this->room('r4')));

        $response->assertOk()
            ->assertSee('This QR code isn’t ready yet')
            ->assertSee('No stairs on the map go up to the 3rd floor of Library yet')
            ->assertDontSee('navigate?from=r4')
            ->assertDontSee('data-qr-print-page', false);
    }

    public function test_a_building_offers_one_card_per_ground_floor_door(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.buildings.qr', $this->library));

        $response->assertOk()
            ->assertSee('Which door will you post it at?')
            ->assertSeeInOrder(['Archive side door', 'Reading Room'])
            ->assertSee(self::SITE.'/navigate?from=r2')
            ->assertSee(self::SITE.'/navigate?from=r1')
            ->assertDontSee('navigate?from=r3')
            ->assertSee('Back to Buildings');
        $this->assertSame(2, substr_count($response->getContent(), '<svg xmlns="http://www.w3.org/2000/svg" class="qr-svg'));
    }

    public function test_a_building_without_a_ground_floor_room_says_why(): void
    {
        $annex = Building::factory()->create(['name' => 'Annex']);
        Room::factory()->for($annex)->create(['floor' => 2]);

        $this->actingAs($this->admin())->get(route('admin.buildings.qr', $annex))
            ->assertOk()
            ->assertSee('This QR code isn’t ready yet')
            ->assertSee('This building has no ground-floor room on the map yet.');
    }

    public function test_it_warns_when_the_code_would_open_an_address_phones_cant_reach(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        $this->actingAs($this->admin())->get(route('admin.rooms.qr', $this->room('r1')))
            ->assertOk()
            ->assertSee('Not ready for posting yet.')
            ->assertSee('http://localhost:8000/navigate?from=r1');
    }
}
