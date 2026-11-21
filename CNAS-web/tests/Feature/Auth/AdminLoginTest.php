<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin account from AdminSeeder signs in through the normal login form and lands on the admin dashboard.
 */
class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = 'cnas-admin';

    private const PASSWORD = 'secret-admin-pass';

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.admin' => [
            'username' => self::USERNAME,
            'email' => 'admin@tcc.edu.ph',
            'password' => self::PASSWORD,
        ]]);
    }

    public function test_the_seeded_admin_signing_in_with_their_username_is_redirected_to_the_admin_dashboard(): void
    {
        $admin = $this->seedAdmin();

        $this->post(route('login.store'), ['login' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_following_the_sign_in_redirect_shows_the_admin_dashboard(): void
    {
        $this->seedAdmin();

        $this->followingRedirects()
            ->post(route('login.store'), ['login' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertSee('Campus overview');
    }

    private function seedAdmin(): User
    {
        $this->seed(AdminSeeder::class);

        return User::where('school_id', self::USERNAME)->sole();
    }
}
