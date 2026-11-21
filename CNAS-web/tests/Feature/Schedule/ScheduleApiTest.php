<?php

namespace Tests\Feature\Schedule;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/schedule for the mobile app.
 */
class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson(route('api.schedule'))->assertUnauthorized();
    }

    public function test_it_returns_the_students_current_term_classes_with_the_matched_room_code(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT']);
        $oldTerm = AcademicTerm::factory()->term('2025-2026', Semester::Second)->create();
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);

        $class = ClassSchedule::factory()->for($student);
        $online = $class->at(Weekday::Tue, '15:00', '17:00')->create(['subject' => 'IT 119', 'room_text' => 'Online']);
        $lab = $class->fromCor()->at(Weekday::Mon, '16:00', '19:00')->create(['subject' => 'IT 119', 'room_text' => 'CompLab1']);
        $class->create(['subject' => 'IT 112', 'academic_term_id' => $oldTerm->id]);
        ClassSchedule::factory()->create(['subject' => 'CRIM 101']);

        Sanctum::actingAs($student);

        $this->getJson(route('api.schedule'))
            ->assertOk()
            ->assertExactJson(['data' => [
                [
                    'id' => $lab->id, 'subject' => 'IT 119', 'section' => 'BSIT3B', 'day' => 'mon', 'starts_at' => '16:00', 'ends_at' => '19:00',
                    'room_text' => 'CompLab1', 'room_code' => 'r6', 'room_name' => 'ComLab 1', 'source' => 'cor',
                ],
                [
                    'id' => $online->id, 'subject' => 'IT 119', 'section' => 'BSIT3B', 'day' => 'tue', 'starts_at' => '15:00', 'ends_at' => '17:00',
                    'room_text' => 'Online', 'room_code' => null, 'room_name' => null, 'source' => 'manual',
                ],
            ]]);
    }
}
