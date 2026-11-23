<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\Building;
use App\Models\Panorama;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The admin top bar search: suggestions while typing (JSON) and the results page (Enter, or no JavaScript).
 */
class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::factory()->create([
            'role' => UserRole::Admin, 'first_name' => 'Campus', 'last_name' => 'Admin', 'school_id' => 'campus-admin', 'email' => 'admin@tcc.edu.ph',
        ]);
    }

    private function suggest(string $query): TestResponse
    {
        return $this->actingAs($this->admin())->getJson(route('admin.search.suggestions', ['q' => $query]))->assertOk();
    }

    /**
     * @return array<string, list<string>> Result titles by group label.
     */
    private function titles(TestResponse $response): array
    {
        return collect($response->json('groups'))->mapWithKeys(fn (array $group) => [$group['label'] => array_column($group['results'], 'title')])->all();
    }

    #[TestWith(['admin.search'])]
    #[TestWith(['admin.search.suggestions'])]
    public function test_guests_are_sent_to_sign_in(string $route): void
    {
        $this->get(route($route, ['q' => 'nee']))->assertRedirect(route('login'));
    }

    #[TestWith(['admin.search'])]
    #[TestWith(['admin.search.suggestions'])]
    public function test_non_admins_are_forbidden(string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Faculty]))
            ->get(route($route, ['q' => 'nee']))
            ->assertForbidden();
    }

    public function test_buildings_are_found_by_name_code_or_landmark_and_open_their_edit_page(): void
    {
        $nee = Building::factory()->create(['code' => 'b1', 'name' => 'NEE Building', 'landmark_name' => 'the Library']);
        Building::factory()->create(['code' => 'b2', 'name' => 'Gymnasium']);

        $this->assertSame(['NEE Building'], $this->titles($this->suggest('nee'))['Buildings']);
        $this->assertSame(['NEE Building'], $this->titles($this->suggest('library'))['Buildings']);
        $this->suggest('b1')->assertJsonPath('groups.0.label', 'Buildings')
            ->assertJsonPath('groups.0.results.0', ['title' => 'NEE Building', 'detail' => 'b1 · Building', 'url' => route('admin.buildings.edit', $nee)]);
    }

    public function test_the_campus_outline_is_not_a_result(): void
    {
        Building::factory()->campus()->create(['name' => 'Tagoloan Community College Campus']);

        $this->assertArrayNotHasKey('Buildings', $this->titles($this->suggest('campus outline')));
        $this->assertSame([], $this->suggest('tagoloan community')->json('groups'));
    }

    public function test_rooms_are_found_by_name_code_or_building_and_every_word_must_match(): void
    {
        $nee = Building::factory()->create(['name' => 'NEE Building']);
        $room = Room::factory()->for($nee)->create(['code' => 'r17', 'name' => 'NEE 201', 'floor' => 2]);
        Room::factory()->create(['code' => 'r4', 'name' => 'ComLab 3']);

        $this->suggest('201')->assertJsonPath('groups.0.results.0', [
            'title' => 'NEE 201', 'detail' => 'r17 · NEE Building · Floor 2', 'url' => route('admin.rooms.edit', $room),
        ]);
        $this->assertSame(['ComLab 3'], $this->titles($this->suggest('r4'))['Rooms']);
        $this->assertSame(['NEE 201'], $this->titles($this->suggest('building 201'))['Rooms']); // "building" matches its building.
        $this->assertArrayNotHasKey('Rooms', $this->titles($this->suggest('comlab 201')));
    }

    public function test_360_photos_are_found_by_name_or_code(): void
    {
        $photo = Panorama::factory()->create(['code' => 'pn7', 'name' => 'Main walkway near the gate', 'floor' => 1]);

        $this->suggest('walkway gate')->assertJsonPath('groups.0', [
            'label' => '360° photos',
            'results' => [['title' => 'Main walkway near the gate', 'detail' => 'pn7 · Floor 1 · Hidden', 'url' => route('admin.panoramas.edit', $photo)]],
        ]);
        $this->assertSame(['Main walkway near the gate'], $this->titles($this->suggest('pn7'))['360° photos']);
    }

    public function test_people_are_found_by_name_school_id_or_email_and_open_their_account_page(): void
    {
        $juan = User::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'school_id' => '20260412', 'email' => 'juan.dc@tcc.edu.ph', 'role' => UserRole::Student]);

        foreach (['juan dela', '20260412', 'juan.dc@'] as $query) {
            $this->assertSame(['Juan Dela Cruz'], $this->titles($this->suggest($query))['People'], $query);
        }
        $this->suggest('20260412')->assertJsonPath('groups.0.results.0', [
            'title' => 'Juan Dela Cruz',
            'detail' => 'Student · 20260412 · juan.dc@tcc.edu.ph',
            'url' => route('admin.accounts.show', $juan),
        ]);
    }

    #[TestWith(['semester', 'Academic term', 'admin.settings', 'academic-term'])]
    #[TestWith(['password', 'My account', 'admin.settings', 'my-account'])]
    #[TestWith(['hotline', 'Emergency contacts', 'admin.settings', 'emergency-contacts'])]
    #[TestWith(['send alert', 'Send an emergency alert', 'admin.alerts.create', null])]
    public function test_admin_pages_are_found_by_name_or_the_words_people_use(string $query, string $title, string $route, ?string $tab): void
    {
        $result = collect($this->suggest($query)->json('groups'))->firstWhere('label', 'Pages')['results'][0];

        $this->assertSame([$title, $tab ? SettingsController::tabUrl($tab) : route($route)], [$result['title'], $result['url']]);
    }

    public function test_the_accounts_page_is_found_now_that_it_exists(): void
    {
        $this->assertSame(['Accounts'], $this->titles($this->suggest('accounts'))['Pages']);
    }

    public function test_groups_come_in_a_fixed_order_with_at_most_five_results_each(): void
    {
        Room::factory()->count(7)->sequence(fn ($sequence) => ['name' => 'Lab '.($sequence->index + 1)])->create();
        Building::factory()->create(['name' => 'Lab Building']);

        $groups = $this->titles($this->suggest('lab'));

        $this->assertSame(['Pages', 'Buildings', 'Rooms'], array_keys($groups)); // "lab" also finds the Rooms page.
        $this->assertSame(['Rooms'], $groups['Pages']);
        $this->assertCount(5, $groups['Rooms']);
    }

    #[TestWith(['n'])]
    #[TestWith([' '])]
    #[TestWith(['%%'])]
    #[TestWith(['_'])]
    public function test_too_short_or_wildcard_only_queries_find_nothing(string $query): void
    {
        Room::factory()->create(['name' => 'NEE 201']);
        User::factory()->create(['first_name' => 'Nora']);

        $this->suggest($query)->assertExactJson([
            'query' => trim($query), 'groups' => [], 'all_url' => route('admin.search', ['q' => trim($query)]),
        ]);
    }

    public function test_wildcards_inside_a_query_are_treated_as_spaces(): void
    {
        Room::factory()->create(['name' => 'NEE 201']);
        Room::factory()->create(['name' => 'Faculty Room']);

        $this->assertSame(['NEE 201'], $this->titles($this->suggest('nee%201'))['Rooms']);
    }

    public function test_the_results_page_lists_every_group_with_links(): void
    {
        $nee = Building::factory()->create(['name' => 'NEE Building']);
        Room::factory()->for($nee)->count(7)->sequence(fn ($sequence) => ['name' => 'NEE '.(200 + $sequence->index)])->create();

        $this->actingAs($this->admin())->get(route('admin.search', ['q' => 'nee']))
            ->assertOk()
            ->assertSeeInOrder(['Buildings', '1 result', 'NEE Building', 'Rooms', '7 results', 'NEE 200', 'NEE 206'])
            ->assertSee('href="'.route('admin.buildings.edit', $nee).'"', false)
            ->assertSee('name="q" type="search" value="nee"', false);
    }

    public function test_the_results_page_explains_short_queries_and_no_matches(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.search'))->assertOk()->assertSee('Type at least 2 letters to search.');
        $this->actingAs($admin)->get(route('admin.search', ['q' => 'zzzz']))
            ->assertOk()
            ->assertSee('No buildings, rooms, photos, people or pages match “zzzz”.');
    }

    public function test_the_top_bar_search_is_a_form_that_opens_the_results_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('role="search" method="GET" action="'.route('admin.search').'" data-admin-search', false)
            ->assertSee('data-suggest-url="'.route('admin.search.suggestions').'"', false)
            ->assertSee('<a href="'.route('admin.search').'" data-search-toggle', false)
            ->assertDontSee('onsubmit="return false"', false);
    }

    public function test_search_text_is_escaped_on_the_results_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.search', ['q' => '<script>alert(1)</script>']))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
