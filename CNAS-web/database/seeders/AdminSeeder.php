<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the admin account from ADMIN_USERNAME, ADMIN_EMAIL and ADMIN_PASSWORD in .env. Safe to run more than once:
 * an existing admin keeps the email and password they set on /admin/settings (.env only gives the first ones); only
 * the role is made sure of again.
 */
class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = config('auth.admin');

        foreach (['username' => 'ADMIN_USERNAME', 'email' => 'ADMIN_EMAIL', 'password' => 'ADMIN_PASSWORD'] as $key => $envName) {
            if (blank($admin[$key] ?? null)) {
                throw new RuntimeException("Set {$envName} in .env before seeding the admin account.");
            }
        }

        $user = User::firstOrNew(['school_id' => $admin['username']]);
        if (! $user->exists) {
            $user->fill([
                'first_name' => 'Campus',
                'last_name' => 'Admin',
                'email' => $admin['email'],
                'password' => $admin['password'],
            ]);
        }
        // role isn't fillable, so it can only be set deliberately like this.
        $user->role = UserRole::Admin;
        $user->email_verified_at ??= now();
        $user->save();
    }
}
