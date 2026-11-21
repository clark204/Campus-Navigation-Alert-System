<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that replaced users.college_id with users.program_code: the backfill rule, and down() restoring the
 * college from the program.
 */
class UserProgramMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_15_090000_replace_users_college_with_program.php');
    }

    public function test_users_take_the_program_of_a_one_program_college_and_the_rest_stay_null(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('colleges')->insert([
            ['id' => 'C01', 'name' => 'College of Information Technology'],
            ['id' => 'C08', 'name' => 'College of Teacher Education'],
        ]);
        DB::table('programs')->insert([
            ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'college_id' => 'C01'],
            ['code' => 'BEED', 'name' => 'Bachelor of Elementary Education', 'college_id' => 'C08'],
            ['code' => 'BSED', 'name' => 'Bachelor of Secondary Education', 'college_id' => 'C08'],
        ]);
        foreach (['it@example.test' => 'C01', 'teacher@example.test' => 'C08', 'admin@example.test' => null] as $email => $collegeId) {
            DB::table('users')->insert([
                'first_name' => 'Test', 'last_name' => 'User', 'email' => $email, 'password' => 'x',
                'role' => 'student', 'college_id' => $collegeId,
            ]);
        }

        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'college_id'));
        $this->assertSame('BSIT', $this->programOf('it@example.test'));
        $this->assertNull($this->programOf('teacher@example.test'));
        $this->assertNull($this->programOf('admin@example.test'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'program_code'));
        $this->assertSame(['C01', null, null], [
            $this->collegeOf('it@example.test'),
            $this->collegeOf('teacher@example.test'),
            $this->collegeOf('admin@example.test'),
        ]);
    }

    private function programOf(string $email): ?string
    {
        return DB::table('users')->where('email', $email)->value('program_code');
    }

    private function collegeOf(string $email): ?string
    {
        return DB::table('users')->where('email', $email)->value('college_id');
    }
}
