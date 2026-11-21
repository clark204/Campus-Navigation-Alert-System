<?php

namespace Tests\Feature\Schedule;

use App\Enums\RoomType;
use App\Enums\ScheduleSource;
use App\Enums\Semester;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Student portal "My schedule": the week view and adding, editing and deleting a class.
 */
class ClassScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CLASS = [
        'subject' => 'IT 119',
        'section' => 'BSIT3B',
        'day' => 'thu',
        'starts_at' => '16:00',
        'ends_at' => '19:00',
        'room_text' => 'CompLab1',
    ];

    /** VALID_CLASS as the form sends it: the room is "Somewhere not on the map" with the text typed in. */
    private const VALID_FORM = [
        'subject' => 'IT 119',
        'section' => 'BSIT3B',
        'day' => 'thu',
        'starts_at' => '16:00',
        'ends_at' => '19:00',
        'room_choice' => 'other',
        'room_other' => 'CompLab1',
    ];

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->student = User::factory()->create(['role' => UserRole::Student, 'program_code' => 'BSIT']);
    }

    /**
     * @param  array<string, ?string>  $overrides
     */
    private function addClass(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->student)->post(route('student.schedule.store'), [...self::VALID_FORM, ...$overrides]);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('student.schedule.index'))->assertRedirect(route('login'));
    }

    #[TestWith([UserRole::Faculty])]
    #[TestWith([UserRole::Staff])]
    #[TestWith([UserRole::Admin])]
    public function test_only_students_have_a_schedule(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('student.schedule.index'))
            ->assertForbidden();
    }

    public function test_an_empty_schedule_points_to_import_and_add(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.schedule.index'))
            ->assertOk()
            ->assertSee('No classes yet')
            ->assertSee(route('student.schedule.import'), false)
            ->assertSee(route('student.schedule.create'), false);
    }

    public function test_the_week_is_grouped_by_day_and_sorted_by_time(): void
    {
        $class = ClassSchedule::factory()->for($this->student);
        $class->at(Weekday::Tue, '13:00', '14:30')->create(['subject' => 'GEC 7']);
        $class->at(Weekday::Mon, '17:30', '19:30')->create(['subject' => 'IT Elec 2']);
        $class->at(Weekday::Mon, '14:30', '16:00')->create(['subject' => 'GEC 5']);
        $this->travelTo(Carbon::parse('2026-11-17 09:00', 'Asia/Manila')); // a Tuesday

        $this->actingAs($this->student)
            ->get(route('student.schedule.index'))
            ->assertOk()
            ->assertSeeInOrder(['Monday', 'GEC 5', '2:30 – 4:00 PM', 'IT Elec 2', '5:30 – 7:30 PM', 'Tuesday', 'Today', 'GEC 7', 'Wednesday', 'No classes'])
            ->assertSee('AY 2026-2027, 1st Semester')
            ->assertDontSee('Sunday');
    }

    public function test_a_room_on_the_map_gets_a_go_button_and_the_others_say_why_not(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);
        $class = ClassSchedule::factory()->for($this->student);
        $class->create(['subject' => 'IT 119', 'room_text' => 'CompLab1']);
        $class->create(['subject' => 'IT 120', 'room_text' => 'Online']);
        $class->create(['subject' => 'PATH FIT 4', 'room_text' => 'FIELD']);

        $response = $this->actingAs($this->student)->get(route('student.schedule.index'));

        $response->assertOk()
            ->assertSee('href="'.route('navigate').'?to=r6" aria-label="Go: directions to ComLab 1"', false)
            ->assertSeeInOrder(['FIELD', '(not on the map yet)'])
            ->assertSee('(online class, no room)');
        $this->assertSame(1, substr_count($response->getContent(), route('navigate').'?to='));
    }

    public function test_classes_of_an_old_term_and_of_other_students_are_not_shown(): void
    {
        $oldTerm = AcademicTerm::factory()->term('2025-2026', Semester::Second)->create();
        ClassSchedule::factory()->for($this->student)->create(['subject' => 'IT 112', 'academic_term_id' => $oldTerm->id]);
        ClassSchedule::factory()->create(['subject' => 'CRIM 101']);
        ClassSchedule::factory()->for($this->student)->create(['subject' => 'IT 119']);

        $this->actingAs($this->student)
            ->get(route('student.schedule.index'))
            ->assertSee('IT 119')
            ->assertDontSee('IT 112')
            ->assertDontSee('CRIM 101');
    }

    public function test_a_student_adds_a_class_by_hand(): void
    {
        $this->addClass()->assertRedirect(route('student.schedule.index'))->assertSessionHas('status', 'IT 119 was added.');

        $class = $this->student->classSchedules()->sole();
        $this->assertSame(
            [...self::VALID_CLASS, 'source' => 'manual', 'academic_term_id' => AcademicTerm::current()->id],
            [...$class->only(['subject', 'section']), 'day' => $class->day->value, ...$class->only(['starts_at', 'ends_at', 'room_text']), 'source' => $class->source->value, 'academic_term_id' => $class->academic_term_id],
        );
    }

    public function test_no_room_chosen_is_saved_as_not_announced(): void
    {
        $this->addClass(['room_choice' => '', 'room_other' => ''])->assertSessionHasNoErrors();

        $this->assertSame('TBA', $this->student->classSchedules()->sole()->room_text);
    }

    public function test_a_room_picked_from_the_list_is_saved_by_its_code_and_gets_directions(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);

        $this->addClass(['room_choice' => 'r6', 'room_other' => ''])->assertSessionHasNoErrors();

        $this->assertSame('r6', $this->student->classSchedules()->sole()->room_text);
        $this->actingAs($this->student)->get(route('student.schedule.index'))
            ->assertSee('href="'.route('navigate').'?to=r6" aria-label="Go: directions to ComLab 1"', false);
    }

    #[TestWith(['r-nope', '', 'room_choice', 'Choose a room from the list.'])]
    #[TestWith(['other', '', 'room_other', 'Type where the class meets, for example Gym.'])]
    public function test_a_room_must_come_from_the_list_or_be_typed_in(string $choice, string $other, string $field, string $message): void
    {
        $this->addClass(['room_choice' => $choice, 'room_other' => $other])->assertSessionHasErrors([$field => $message]);

        $this->assertSame(0, ClassSchedule::count());
    }

    public function test_the_form_lists_the_maps_rooms_by_building_with_not_announced_first(): void
    {
        Room::factory()->for(Building::factory()->state(['name' => 'NEE Building', 'landmark_name' => null]))->create(['code' => 'r17', 'name' => 'NEE 201', 'floor' => 2]);
        Room::factory()->create(['code' => 'r28', 'name' => 'NEE Bathroom', 'type' => RoomType::Cr]);

        $this->actingAs($this->student)->get(route('student.schedule.create'))
            ->assertOk()
            ->assertDontSee('NEE Bathroom')
            ->assertSeeInOrder([
                '<option value="TBA" selected>Not announced yet</option>',
                '<option value="Online" >Online class</option>',
                '<optgroup label="NEE Building">',
                '<option value="r17" >NEE 201 · 2nd floor</option>',
                'Somewhere not on the map',
            ], false);
    }

    #[TestWith(['ends_at', '16:00', 'The class must end after it starts.'])]
    #[TestWith(['ends_at', '15:00', 'The class must end after it starts.'])]
    #[TestWith(['ends_at', '7pm', 'Enter the time the class ends.'])]
    #[TestWith(['day', 'someday', 'Choose the day of the class.'])]
    #[TestWith(['subject', '', 'Enter the subject, for example IT 119.'])]
    public function test_a_class_that_is_not_valid_is_not_saved(string $field, string $value, string $message): void
    {
        $this->addClass([$field => $value])->assertSessionHasErrors([$field => $message]);

        $this->assertSame(0, ClassSchedule::count());
    }

    public function test_the_edit_form_shows_the_class(): void
    {
        $class = ClassSchedule::factory()->for($this->student)->fromCor()->at(Weekday::Thu, '16:00', '19:00')->create(['subject' => 'IT 119']);

        $this->actingAs($this->student)
            ->get(route('student.schedule.edit', $class))
            ->assertOk()
            ->assertSee('value="IT 119"', false)
            ->assertSee('value="16:00"', false)
            ->assertSee('This class came from your COR.')
            ->assertSee('Delete class');
    }

    public function test_editing_selects_the_matched_room_or_keeps_the_text_as_written(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);
        $onMap = ClassSchedule::factory()->for($this->student)->fromCor()->create(['room_text' => 'CompLab1']);
        $offMap = ClassSchedule::factory()->for($this->student)->fromCor()->create(['room_text' => 'FIELD']);

        $this->actingAs($this->student)->get(route('student.schedule.edit', $onMap))
            ->assertSee('<option value="r6" selected>ComLab 1</option>', false);
        $this->actingAs($this->student)->get(route('student.schedule.edit', $offMap))
            ->assertSee('<option value="other" selected>', false)
            ->assertSee('value="FIELD"', false);
    }

    public function test_a_cor_class_the_student_changes_becomes_manual(): void
    {
        $class = ClassSchedule::factory()->for($this->student)->fromCor()->create(self::VALID_CLASS);

        $this->actingAs($this->student)
            ->put(route('student.schedule.update', $class), [...self::VALID_FORM, 'room_choice' => 'other', 'room_other' => 'ComLab 3'])
            ->assertRedirect(route('student.schedule.index'));

        $class->refresh();
        $this->assertSame(['ComLab 3', ScheduleSource::Manual], [$class->room_text, $class->source]);
    }

    public function test_a_cor_class_saved_without_changes_stays_a_cor_class(): void
    {
        $class = ClassSchedule::factory()->for($this->student)->fromCor()->create(self::VALID_CLASS);

        $this->actingAs($this->student)->put(route('student.schedule.update', $class), self::VALID_FORM)->assertRedirect();

        $this->assertSame(ScheduleSource::Cor, $class->refresh()->source);
    }

    public function test_a_cor_class_saved_with_its_matched_room_still_selected_stays_as_written(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);
        $class = ClassSchedule::factory()->for($this->student)->fromCor()->create(self::VALID_CLASS);

        // The form shows ComLab 1 (r6) selected for "CompLab1"; saving it unchanged sends r6.
        $this->actingAs($this->student)->put(route('student.schedule.update', $class), [...self::VALID_FORM, 'room_choice' => 'r6', 'room_other' => ''])->assertRedirect();

        $class->refresh();
        $this->assertSame(['CompLab1', ScheduleSource::Cor], [$class->room_text, $class->source]);
    }

    public function test_a_student_deletes_their_class(): void
    {
        $class = ClassSchedule::factory()->for($this->student)->create();

        $this->actingAs($this->student)
            ->delete(route('student.schedule.destroy', $class))
            ->assertRedirect(route('student.schedule.index'));

        $this->assertModelMissing($class);
    }

    public function test_a_student_cannot_open_change_or_delete_another_students_class(): void
    {
        $theirs = ClassSchedule::factory()->create(['subject' => 'CRIM 101']);

        $this->actingAs($this->student)->get(route('student.schedule.edit', $theirs))->assertForbidden();
        $this->actingAs($this->student)->put(route('student.schedule.update', $theirs), self::VALID_FORM)->assertForbidden();
        $this->actingAs($this->student)->delete(route('student.schedule.destroy', $theirs))->assertForbidden();

        $this->assertSame('CRIM 101', $theirs->refresh()->subject);
    }

    public function test_a_class_of_an_old_term_cannot_be_changed(): void
    {
        $oldTerm = AcademicTerm::factory()->term('2025-2026', Semester::Second)->create();
        $old = ClassSchedule::factory()->for($this->student)->create(['academic_term_id' => $oldTerm->id]);

        $this->actingAs($this->student)->put(route('student.schedule.update', $old), self::VALID_FORM)->assertForbidden();
        $this->actingAs($this->student)->delete(route('student.schedule.destroy', $old))->assertForbidden();

        $this->assertModelExists($old);
    }
}
