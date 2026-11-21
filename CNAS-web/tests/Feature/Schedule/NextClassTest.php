<?php

namespace Tests\Feature\Schedule;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\User;
use App\Services\ClassSchedules;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Next class" on the student's Home. 2026-11-16 is a Monday; times are campus time (Asia/Manila, UTC+8).
 */
class NextClassTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->student = User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT']);
    }

    /**
     * Monday 08:00-09:30 (IT 101), Monday 13:00-14:30 (IT 102) and Wednesday 10:00-11:30 (IT 103).
     *
     * @return Collection<int, ClassSchedule>
     */
    private function classes(): Collection
    {
        $class = ClassSchedule::factory()->for($this->student);

        return new Collection([
            $class->at(Weekday::Mon, '13:00', '14:30')->create(['subject' => 'IT 102']),
            $class->at(Weekday::Mon, '08:00', '09:30')->create(['subject' => 'IT 101']),
            $class->at(Weekday::Wed, '10:00', '11:30')->create(['subject' => 'IT 103']),
        ]);
    }

    #[DataProvider('moments')]
    public function test_the_next_class_is_the_first_one_that_has_not_ended(string $campusTime, string $subject, string $when): void
    {
        $next = app(ClassSchedules::class)->next($this->classes(), Carbon::parse($campusTime, 'Asia/Manila'));

        $this->assertSame([$subject, $when], [$next['class']->subject, $next['when']]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function moments(): array
    {
        return [
            'before the first class today' => ['2026-11-16 07:00', 'IT 101', 'Today'],
            'a class in progress is still next' => ['2026-11-16 08:30', 'IT 101', 'Now'],
            'a class that just ended is skipped' => ['2026-11-16 09:30', 'IT 102', 'Today'],
            'after the last class today' => ['2026-11-16 15:00', 'IT 103', 'Wednesday'],
            'the day before' => ['2026-11-17 12:00', 'IT 103', 'Tomorrow'],
            'the week wraps around' => ['2026-11-19 09:00', 'IT 101', 'Monday'],
            'same day next week' => ['2026-11-18 12:00', 'IT 101', 'Monday'],
        ];
    }

    public function test_the_time_is_read_on_campus_time_not_utc(): void
    {
        // Sunday 23:30 UTC is already Monday 07:30 on campus.
        $next = app(ClassSchedules::class)->next($this->classes(), Carbon::parse('2026-11-15 23:30', 'UTC'));

        $this->assertSame(['IT 101', 'Today'], [$next['class']->subject, $next['when']]);
    }

    public function test_there_is_no_next_class_without_classes(): void
    {
        $this->assertNull(app(ClassSchedules::class)->next(new Collection, now()));
    }

    public function test_home_shows_the_next_class_and_todays_classes_above_my_college(): void
    {
        // An IT room, so "My college" (the map's IT rooms) shows for this IT student.
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1', 'is_it' => true]);
        $this->classes()->firstWhere('subject', 'IT 102')->update(['room_text' => 'CompLab1']);
        $this->travelTo(Carbon::parse('2026-11-16 10:00', 'Asia/Manila'));

        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Next class', 'Today', 'IT 102', '1:00 – 2:30 PM', 'ComLab 1', 'IT 101', 'IT 102', 'My college'])
            ->assertSee(route('navigate').'?to=r6', false)
            ->assertDontSee('IT 103');
    }

    public function test_home_points_a_student_without_a_schedule_to_import_and_add(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Next class')
            ->assertSee(route('student.schedule.import'), false)
            ->assertSee(route('student.schedule.create'), false);
    }
}
