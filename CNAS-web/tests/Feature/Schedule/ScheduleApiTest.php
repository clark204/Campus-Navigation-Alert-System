<?php

namespace Tests\Feature\Schedule;

use App\Enums\ScheduleSource;
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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * /api/schedule for the mobile app: "My schedule" (students) and "My classes" (faculty), with the web's rules.
 */
class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->student = User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT', 'school_id' => CorFixture::STUDENT_ID]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private static function form(array $overrides = []): array
    {
        return [
            'subject' => 'IT 119', 'section' => 'BSIT3B', 'day' => 'mon', 'starts_at' => '16:00', 'ends_at' => '19:00', 'room_choice' => 'TBA',
            ...$overrides,
        ];
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson(route('api.schedule'))->assertUnauthorized();
        $this->postJson(route('api.schedule.store'), self::form())->assertUnauthorized();
    }

    public function test_it_returns_the_students_current_term_classes_with_the_matched_room_code(): void
    {
        $oldTerm = AcademicTerm::factory()->term('2025-2026', Semester::Second)->create();
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);

        $class = ClassSchedule::factory()->for($this->student);
        $online = $class->at(Weekday::Tue, '15:00', '17:00')->create(['subject' => 'IT 119', 'room_text' => 'Online']);
        $lab = $class->fromCor()->at(Weekday::Mon, '16:00', '19:00')->create(['subject' => 'IT 119', 'room_text' => 'CompLab1']);
        $gym = $class->at(Weekday::Wed, '08:00', '10:00')->create(['subject' => 'PE 3', 'room_text' => 'Gym']);
        $class->create(['subject' => 'IT 112', 'academic_term_id' => $oldTerm->id]);
        ClassSchedule::factory()->create(['subject' => 'CRIM 101']);

        Sanctum::actingAs($this->student);

        $this->getJson(route('api.schedule'))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => $lab->id, 'subject' => 'IT 119', 'section' => 'BSIT3B', 'day' => 'mon', 'starts_at' => '16:00', 'ends_at' => '19:00',
                        'room_text' => 'CompLab1', 'room_code' => 'r6', 'room_name' => 'ComLab 1', 'room_choice' => 'r6', 'room_other' => null, 'source' => 'cor',
                    ],
                    [
                        'id' => $online->id, 'subject' => 'IT 119', 'section' => 'BSIT3B', 'day' => 'tue', 'starts_at' => '15:00', 'ends_at' => '17:00',
                        'room_text' => 'Online', 'room_code' => null, 'room_name' => null, 'room_choice' => 'Online', 'room_other' => null, 'source' => 'manual',
                    ],
                    [
                        'id' => $gym->id, 'subject' => 'PE 3', 'section' => 'BSIT3B', 'day' => 'wed', 'starts_at' => '08:00', 'ends_at' => '10:00',
                        'room_text' => 'Gym', 'room_code' => null, 'room_name' => null, 'room_choice' => 'other', 'room_other' => 'Gym', 'source' => 'manual',
                    ],
                ],
                'meta' => ['term' => 'AY 2026-2027, 1st Semester'],
            ]);
    }

    public function test_the_room_list_has_the_fixed_choices_and_the_maps_class_rooms(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);
        Room::factory()->create(['code' => 'r9', 'name' => 'CR', 'type' => 'cr']);

        Sanctum::actingAs($this->student);

        $response = $this->getJson(route('api.schedule.rooms'))->assertOk();

        $this->assertSame(['TBA', 'Online', 'other'], $response->json('choices.*.value'));
        $this->assertSame(['r6'], $response->json('buildings.*.rooms.*.code'));
    }

    public function test_a_class_is_added_to_the_current_term_by_hand(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);
        Sanctum::actingAs($this->student);

        $this->postJson(route('api.schedule.store'), self::form(['room_choice' => 'r6']))
            ->assertCreated()
            ->assertJsonPath('message', 'IT 119 was added.')
            ->assertJsonPath('data.room_code', 'r6')
            ->assertJsonPath('data.source', 'manual');

        $this->assertDatabaseHas('class_schedules', [
            'user_id' => $this->student->id, 'subject' => 'IT 119', 'room_text' => 'r6',
            'academic_term_id' => AcademicTerm::current()->id, 'source' => 'manual',
        ]);
    }

    public function test_adding_uses_the_web_forms_messages(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson(route('api.schedule.store'), self::form(['ends_at' => '15:00', 'room_choice' => 'other', 'room_other' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'ends_at' => 'The class must end after it starts.',
                'room_other' => 'Type where the class meets, for example Gym.',
            ]);
    }

    public function test_nothing_is_added_without_a_current_term(): void
    {
        AcademicTerm::current()->forceFill(['is_current' => false])->save();
        Sanctum::actingAs($this->student);

        $this->postJson(route('api.schedule.store'), self::form())
            ->assertConflict()
            ->assertJsonPath('message', 'There is no current term yet, so classes cannot be added.');
        $this->assertDatabaseCount('class_schedules', 0);
    }

    public function test_saving_a_cor_row_unchanged_keeps_it_from_the_cor(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);
        $class = ClassSchedule::factory()->for($this->student)->fromCor()->at(Weekday::Mon, '16:00', '19:00')
            ->create(['subject' => 'IT 119', 'room_text' => 'CompLab1']);
        Sanctum::actingAs($this->student);

        $this->putJson(route('api.schedule.update', $class), self::form(['room_choice' => 'r6']))
            ->assertOk()
            ->assertJsonPath('message', 'IT 119 was saved.')
            ->assertJsonPath('data.source', 'cor');

        $this->assertSame(['CompLab1', ScheduleSource::Cor], [$class->fresh()->room_text, $class->fresh()->source]);
    }

    public function test_a_real_change_to_a_cor_row_makes_it_manual(): void
    {
        $class = ClassSchedule::factory()->for($this->student)->fromCor()->at(Weekday::Mon, '16:00', '19:00')->create(['subject' => 'IT 119']);
        Sanctum::actingAs($this->student);

        $this->putJson(route('api.schedule.update', $class), self::form(['starts_at' => '17:00']))
            ->assertOk()
            ->assertJsonPath('data.starts_at', '17:00')
            ->assertJsonPath('data.source', 'manual');
    }

    public function test_nobody_changes_or_deletes_another_persons_class(): void
    {
        $theirs = ClassSchedule::factory()->create(['subject' => 'CRIM 101']);
        Sanctum::actingAs($this->student);

        $this->putJson(route('api.schedule.update', $theirs), self::form())->assertForbidden();
        $this->deleteJson(route('api.schedule.destroy', $theirs))->assertForbidden();
        $this->assertModelExists($theirs);
    }

    public function test_a_class_is_deleted(): void
    {
        $class = ClassSchedule::factory()->for($this->student)->create(['subject' => 'IT 119']);
        Sanctum::actingAs($this->student);

        $this->deleteJson(route('api.schedule.destroy', $class))->assertOk()->assertJsonPath('message', 'IT 119 was deleted.');
        $this->assertModelMissing($class);
    }

    public function test_faculty_add_classes_but_staff_do_not_teach(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Faculty]));
        $this->postJson(route('api.schedule.store'), self::form())->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Staff]));
        $this->postJson(route('api.schedule.store'), self::form())->assertForbidden();
    }

    public function test_a_student_imports_their_own_cor_and_gets_the_new_schedule(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->student);

        $this->postJson(route('api.schedule.import'), ['cor' => CorFixture::upload(CorFixture::text(CorFixture::CURRENT_SMALOT))])
            ->assertOk()
            ->assertJsonPath('message', '15 classes imported from your COR.')
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.term', 'AY 2026-2027, 1st Semester');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_cor_of_another_student_is_refused(): void
    {
        $this->student->update(['school_id' => '20249999']);
        Sanctum::actingAs($this->student);

        $this->postJson(route('api.schedule.import'), ['cor' => CorFixture::upload(CorFixture::text(CorFixture::CURRENT_SMALOT))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cor' => 'This COR belongs to another student. Please upload your own COR.']);
        $this->assertDatabaseCount('class_schedules', 0);
    }

    public function test_only_students_import_a_cor(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Faculty]));

        $this->postJson(route('api.schedule.import'), ['cor' => CorFixture::upload(CorFixture::text(CorFixture::CURRENT_SMALOT))])->assertForbidden();
    }
}
