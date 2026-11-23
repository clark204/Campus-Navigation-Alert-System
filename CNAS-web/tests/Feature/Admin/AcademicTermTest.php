<?php

namespace Tests\Feature\Admin;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\AcademicTerm;
use App\Models\User;
use Database\Seeders\AcademicTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AcademicTermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_the_seeder_makes_2026_2027_first_semester_current(): void
    {
        $this->seed(AcademicTermSeeder::class);

        $this->assertSame('AY 2026-2027, 1st Semester', AcademicTerm::current()?->label());
    }

    public function test_only_one_term_is_current_at_a_time(): void
    {
        $first = AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $second = AcademicTerm::factory()->term('2026-2027', Semester::Second)->create();
        $this->freezeSecond();

        $second->makeCurrent();

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
        $this->assertEquals(now(), $second->fresh()->set_current_at);
        $this->assertSame(1, AcademicTerm::where('is_current', true)->count());
        $this->assertTrue(AcademicTerm::current()->is($second));
    }

    public function test_current_is_null_without_a_current_term(): void
    {
        AcademicTerm::factory()->create();

        $this->assertNull(AcademicTerm::current());
    }

    public function test_the_old_page_opens_the_settings_tab(): void
    {
        $this->actingAs($this->admin())->get(route('admin.academic-terms.index'))
            ->assertRedirect(SettingsController::tabUrl('academic-term'));
    }

    public function test_the_settings_tab_shows_the_current_term_and_all_terms(): void
    {
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        AcademicTerm::factory()->term('2025-2026', Semester::Second)->create();

        $this->actingAs($this->admin())->get(SettingsController::tabUrl('academic-term'))
            ->assertOk()
            ->assertSee('id="academic-term" role="tabpanel" aria-labelledby="tab-academic-term"', false)
            ->assertSeeInOrder(['Current term', 'AY 2026-2027, 1st Semester', 'All terms'])
            ->assertSee('AY 2025-2026, 2nd Semester')
            ->assertSee('data-confirm="Set AY 2025-2026, 2nd Semester as current? Students can then only register with a COR for this term."', false);
    }

    public function test_an_admin_adds_a_term(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.academic-terms.store'), ['academic_year' => '2027-2028', 'semester' => 'summer'])
            ->assertRedirect(SettingsController::tabUrl('academic-term'))
            ->assertSessionHas('status', "AY 2027-2028, Summer was added. It isn't current until you set it.");

        $term = AcademicTerm::sole();
        $this->assertSame(['2027-2028', Semester::Summer, false], [$term->academic_year, $term->semester, $term->is_current]);
    }

    #[TestWith(['2026-2028'])]
    #[TestWith(['2026/2027'])]
    #[TestWith(['26-27'])]
    #[TestWith(['2027-2026'])]
    public function test_the_year_must_be_consecutive_years(string $academicYear): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.academic-terms.store'), ['academic_year' => $academicYear, 'semester' => '1st'])
            ->assertRedirect(SettingsController::tabUrl('academic-term'))
            ->assertSessionHasErrors(['academic_year' => 'The academic year must look like 2026-2027, with the second year one after the first.'], errorBag: 'academicTerm');

        $this->assertDatabaseCount('academic_terms', 0);
    }

    public function test_the_semester_must_be_known(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.academic-terms.store'), ['academic_year' => '2026-2027', 'semester' => '3rd'])
            ->assertSessionHasErrors(['semester' => 'Choose a semester.'], errorBag: 'academicTerm');
    }

    public function test_a_term_cannot_be_added_twice(): void
    {
        AcademicTerm::factory()->term('2026-2027', Semester::First)->create();

        $this->actingAs($this->admin())
            ->post(route('admin.academic-terms.store'), ['academic_year' => '2026-2027', 'semester' => '1st'])
            ->assertSessionHasErrors(['academic_year' => 'That term already exists.'], errorBag: 'academicTerm');
    }

    public function test_an_admin_sets_the_current_term(): void
    {
        $old = AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $new = AcademicTerm::factory()->term('2026-2027', Semester::Second)->create();

        $this->actingAs($this->admin())
            ->post(route('admin.academic-terms.current', $new))
            ->assertRedirect(SettingsController::tabUrl('academic-term'))
            ->assertSessionHas('status', 'AY 2026-2027, 2nd Semester is now the current term.');

        $this->assertFalse($old->fresh()->is_current);
        $this->assertTrue(AcademicTerm::current()->is($new));
    }

    public function test_the_dashboard_reminds_the_admin_after_five_months(): void
    {
        $this->freezeSecond();
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $admin = $this->admin();

        $this->travel(5)->months();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertDontSee('Is it still right?');

        $this->travel(1)->day();
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('The current term (AY 2026-2027, 1st Semester) was set')
            ->assertSee('Is it still right?')
            ->assertSee(e(SettingsController::tabUrl('academic-term')), false);
    }

    public function test_the_dashboard_warns_when_registration_is_closed(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertSee('Registration is closed: no academic term is set as current.');
    }
}
