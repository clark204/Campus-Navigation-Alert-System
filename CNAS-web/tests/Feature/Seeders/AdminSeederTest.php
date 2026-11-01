<?php

namespace Tests\Feature\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.admin' => [
            'username' => 'campus-admin',
            'email' => 'admin@example.com',
            'password' => 'first-password',
        ]]);
    }

    public function test_it_creates_the_admin_from_config(): void
    {
        $this->seed(AdminSeeder::class);

        $admin = User::sole();
        $this->assertSame('campus-admin', $admin->school_id);
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('first-password', $admin->password));
    }

    public function test_running_it_again_updates_the_same_account(): void
    {
        $this->seed(AdminSeeder::class);
        config(['auth.admin.password' => 'second-password']);

        $this->seed(AdminSeeder::class);

        $this->assertTrue(Hash::check('second-password', User::sole()->password));
    }

    public function test_it_refuses_to_run_without_a_password(): void
    {
        config(['auth.admin.password' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD');

        $this->seed(AdminSeeder::class);
    }
}
