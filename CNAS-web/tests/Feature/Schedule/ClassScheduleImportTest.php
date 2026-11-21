<?php

namespace Tests\Feature\Schedule;

use App\Enums\ScheduleSource;
use App\Enums\Semester;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * Class schedules filled from a COR: the portal's "Import from COR" and the registration checkbox.
 * The COR fixture has 15 class meetings for student 20240001, AY 2026-2027 1st.
 */
class ClassScheduleImportTest extends TestCase
{
    use RefreshDatabase;

    private const COR_MEETINGS = 15;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->student = User::factory()->create([
            'role' => UserRole::Student,
            'program_code' => 'BSIT',
            'school_id' => CorFixture::STUDENT_ID,
        ]);
    }

    private static function cor(?string $text = null): UploadedFile
    {
        return CorFixture::upload($text ?? CorFixture::text(CorFixture::CURRENT_SMALOT));
    }

    private function import(?UploadedFile $file = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->student)->post(route('student.schedule.import.store'), ['cor' => $file ?? self::cor()]);
    }

    public function test_the_import_step_says_that_hand_changed_classes_are_kept(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.schedule.import'))
            ->assertOk()
            ->assertSee('AY 2026-2027, 1st Semester')
            ->assertSee('Classes you changed by hand are kept.')
            ->assertSee('name="cor"', false);
    }

    public function test_importing_a_cor_saves_one_row_per_meeting_and_keeps_no_file(): void
    {
        $this->import()
            ->assertRedirect(route('student.schedule.index'))
            ->assertSessionHas('status', '15 classes imported from your COR.');

        $classes = $this->student->classSchedules()->get();
        $this->assertCount(self::COR_MEETINGS, $classes);
        $this->assertTrue($classes->every(fn (ClassSchedule $class) => $class->source === ScheduleSource::Cor
            && $class->academic_term_id === AcademicTerm::current()->id));

        $thursday = $classes->where('subject', 'IT 119')->firstWhere('day', Weekday::Thu);
        $this->assertSame(['BSIT3B', '16:00', '19:00', 'CompLab1'], array_values($thursday->only(['section', 'starts_at', 'ends_at', 'room_text'])));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_importing_again_replaces_only_the_cor_rows(): void
    {
        ClassSchedule::factory()->for($this->student)->fromCor()->create(['subject' => 'OLD 101']);
        $manual = ClassSchedule::factory()->for($this->student)->create(['subject' => 'Thesis consultation']);
        $otherStudents = ClassSchedule::factory()->fromCor()->create(['subject' => 'CRIM 101']);

        $this->import()->assertSessionHasNoErrors();
        $this->import()->assertSessionHasNoErrors();

        $subjects = $this->student->classSchedules()->pluck('subject');
        $this->assertCount(self::COR_MEETINGS + 1, $subjects);
        $this->assertNotContains('OLD 101', $subjects);
        $this->assertModelExists($manual);
        $this->assertModelExists($otherStudents);
    }

    public function test_a_class_the_student_edited_survives_a_new_import(): void
    {
        $this->import();
        $thursday = $this->student->classSchedules()->where('subject', 'IT 119')->where('day', Weekday::Thu)->sole();

        $this->actingAs($this->student)->put(route('student.schedule.update', $thursday), [
            'subject' => 'IT 119', 'section' => 'BSIT3B', 'day' => 'thu', 'starts_at' => '16:00', 'ends_at' => '19:00', 'room_choice' => 'other', 'room_other' => 'ComLab 3',
        ])->assertSessionHasNoErrors();

        $this->import()->assertSessionHas('status', '14 classes imported from your COR. 1 class you changed by hand was kept.');

        $thursdays = $this->student->classSchedules()->where('subject', 'IT 119')->where('day', Weekday::Thu)->get();
        $this->assertCount(1, $thursdays);
        $this->assertSame(['ComLab 3', ScheduleSource::Manual], [$thursdays[0]->room_text, $thursdays[0]->source]);
        $this->assertSame(self::COR_MEETINGS, $this->student->classSchedules()->count());
    }

    public function test_a_cor_of_another_student_is_rejected(): void
    {
        $someoneElse = User::factory()->create(['role' => UserRole::Student, 'school_id' => '20249999']);

        $this->import(as: $someoneElse)
            ->assertSessionHasErrors(['cor' => 'This COR belongs to another student. Please upload your own COR.']);

        $this->assertSame(0, ClassSchedule::count());
    }

    public function test_the_cor_is_checked_as_at_registration(): void
    {
        $this->import(UploadedFile::fake()->image('cor.jpg'))
            ->assertSessionHasErrors(['cor' => 'Please upload your COR as a PDF file (up to 5 MB).']);
        $this->import(CorFixture::upload(CorFixture::tampered('Total Units : 27', 'Total Units : 30')))
            ->assertSessionHasErrors(['cor' => 'Invalid COR.']);
        $this->import(self::cor(CorFixture::text(CorFixture::OLDER_SMALOT)))
            ->assertSessionHasErrors(['cor' => 'Please upload your COR for AY 2026-2027, 1st Semester.']);

        $this->assertSame(0, ClassSchedule::count());
    }

    public function test_nothing_is_imported_without_a_current_term(): void
    {
        AcademicTerm::query()->delete();

        $this->import()->assertSessionHasErrors('cor');

        $this->assertSame(0, ClassSchedule::count());
    }

    public function test_only_students_import(): void
    {
        $faculty = User::factory()->create(['role' => UserRole::Faculty, 'school_id' => '20240002']);

        $this->import(as: $faculty)->assertForbidden();
    }

    /**
     * Web registration: upload the COR, then create the account with or without the checkbox.
     */
    private function register(bool $saveSchedule): User
    {
        $this->student->delete();
        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(8, 0));

        $this->post(route('register.cor'), ['cor' => self::cor(), 'consent' => '1'])->assertRedirect(route('register.confirm'));
        $this->get(route('register.confirm'))
            ->assertOk()
            ->assertSee('Also save my class schedule')
            ->assertSee('name="save_schedule" value="1" checked', false);

        $this->post(route('register.store'), array_filter([
            'idempotency_key' => (string) Str::uuid(),
            'email' => 'juan@tcc.edu.ph',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'save_schedule' => $saveSchedule ? '1' : null,
        ]))->assertRedirect(route('email.verify'));

        return User::where('school_id', CorFixture::STUDENT_ID)->sole();
    }

    public function test_registration_saves_the_cor_schedule_when_the_box_is_ticked(): void
    {
        $user = $this->register(saveSchedule: true);

        $this->assertSame(self::COR_MEETINGS, $user->classSchedules()->where('source', ScheduleSource::Cor)->count());
        $this->assertSame(AcademicTerm::current()->id, $user->classSchedules()->first()->academic_term_id);
    }

    public function test_registration_saves_no_schedule_when_the_box_is_unticked(): void
    {
        $user = $this->register(saveSchedule: false);

        $this->assertSame(0, $user->classSchedules()->count());
    }
}
