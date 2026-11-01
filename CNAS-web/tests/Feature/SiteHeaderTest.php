<?php

namespace Tests\Feature;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * Site header actions for guests and signed-in users.
 */
class SiteHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_start_and_sign_in(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('href="'.route('register').'" class="header-cta">Start</a>', false)
            ->assertSee('href="'.route('login').'" class="header-button">Sign in</a>', false)
            ->assertSeeInOrder(['class="header-button">Sign in</a>', 'class="header-cta">Start</a>'], false)
            ->assertDontSee('Dashboard')
            ->assertDontSee('Sign out');
    }

    public function test_login_and_registration_pages_show_neither_start_nor_sign_in(): void
    {
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(8, 0));

        $this->post(route('register.cor'), ['cor' => CorFixture::upload(), 'consent' => '1'])
            ->assertRedirect(route('register.confirm'));

        foreach (['login', 'register', 'register.confirm'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertDontSee('class="header-cta"', false)
                ->assertDontSee('class="header-button"', false);
        }
    }

    public function test_signed_in_users_see_dashboard_and_sign_out(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]));

        $this->view('partials.site-header')
            ->assertSee('href="'.route('student.dashboard').'" class="header-cta">Dashboard</a>', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('Sign out')
            ->assertDontSee('>Start<', false)
            ->assertDontSee('>Sign in<', false);
    }
}
