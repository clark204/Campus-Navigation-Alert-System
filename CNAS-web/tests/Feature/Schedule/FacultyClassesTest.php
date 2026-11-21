<?php

namespace Tests\Feature\Schedule;

use App\Enums\ScheduleSource;
use App\Enums\Semester;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Faculty portal "My classes": teachers add the classes they teach by hand (no COR for faculty), see them on Home, and
 * staff (who share the faculty portal but don't teach) never get any of it. Also the admin's Faculty / Staff switch.
 */
class FacultyClassesTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CLASS = [
        'subject' => 'IT 213',
        'section' => 'BSIT2A',
        'day' => 'mon',
        'starts_at' => '10:00',
        'ends_at' => '11:30',
        'room_choice' => 'r12',
    ];

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->term = AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
    }

    private function faculty(): User
    {
        return User::factory()->create(['role' => UserRole::Faculty]);
    }

    public function test_a_teacher_adds_a_class_by_hand_and_sees_it_in_my_classes(): void
    {
        $faculty = $this->faculty();
        Room::factory()->create(['code' => 'r12', 'name' => 'ComLab 4']);

        $this->actingAs($faculty)
            ->post(route('faculty.classes.store'), self::VALID_CLASS)
            ->assertRedirect(route('faculty.classes.index'))
            ->assertSessionHas('status', 'IT 213 was added.');

        $class = ClassSchedule::sole();
        $this->assertTrue($class->user->is($faculty));
        $this->assertSame($this->term->id, $class->academic_term_id);
        $this->assertSame(ScheduleSource::Manual, $class->source);
        $this->assertSame('r12', $class->room_text);

        $this->actingAs($faculty)->get(route('faculty.classes.index'))
            ->assertOk()
            ->assertSeeInOrder(['My classes', 'Monday', 'IT 213', 'BSIT2A', '10:00 – 11:30 AM', 'ComLab 4'])
            ->assertSee(route('faculty.classes.edit', $class), false)
            ->assertDontSee('Import from COR');
    }

    public function test_a_teacher_edits_and_deletes_their_class(): void
    {
        $faculty = $this->faculty();
        $class = ClassSchedule::factory()->for($faculty)->create(['subject' => 'IT 213']);
        Room::factory()->create(['code' => 'r12', 'name' => 'ComLab 4']);

        $this->actingAs($faculty)->get(route('faculty.classes.edit', $class))->assertOk()->assertSee('action="'.route('faculty.classes.update', $class).'"', false);
        $this->actingAs($faculty)
            ->put(route('faculty.classes.update', $class), [...self::VALID_CLASS, 'subject' => 'IT 214'])
            ->assertRedirect(route('faculty.classes.index'));
        $this->assertSame('IT 214', $class->fresh()->subject);

        $this->actingAs($faculty)->delete(route('faculty.classes.destroy', $class))->assertRedirect(route('faculty.classes.index'));
        $this->assertModelMissing($class);
    }

    public function test_a_teacher_cannot_touch_another_teachers_class(): void
    {
        $class = ClassSchedule::factory()->for($this->faculty())->create();

        $other = $this->faculty();

        $this->actingAs($other)->get(route('faculty.classes.edit', $class))->assertForbidden();
        $this->actingAs($other)->put(route('faculty.classes.update', $class), [...self::VALID_CLASS, 'subject' => 'HACKED'])->assertForbidden();
        $this->actingAs($other)->delete(route('faculty.classes.destroy', $class))->assertForbidden();

        $this->assertModelExists($class);
        $this->assertNotSame('HACKED', $class->fresh()->subject);
    }

    #[TestWith([UserRole::Staff])]
    #[TestWith([UserRole::Student])]
    public function test_only_faculty_have_my_classes(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get(route('faculty.classes.index'))->assertForbidden();
    }

    public function test_home_shows_a_teachers_real_next_class_with_directions(): void
    {
        $faculty = $this->faculty();
        Room::factory()->create(['code' => 'r12', 'name' => 'ComLab 4']);
        ClassSchedule::factory()->for($faculty)->create(['subject' => 'IT 213', 'day' => 'mon', 'starts_at' => '10:00', 'ends_at' => '11:30', 'room_text' => 'r12', 'source' => ScheduleSource::Manual]);
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila')); // A Monday, before the class.

        $this->actingAs($faculty)->get(route('faculty.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Next class', 'Today', 'IT 213', '10:00 – 11:30 AM', 'ComLab 4'])
            ->assertSee(route('navigate', ['to' => 'r12']), false);
    }

    public function test_the_admin_switches_a_staff_member_who_teaches_to_faculty(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'first_name' => 'Rosa', 'last_name' => 'Lim']);

        $this->actingAs($admin)->get(route('admin.accounts.show', $staff))->assertSee('Faculty or staff');
        $this->actingAs($admin)->from(route('admin.accounts.show', $staff))
            ->patch(route('admin.accounts.role', $staff), ['role' => 'faculty'])
            ->assertRedirect(route('admin.accounts.show', $staff))
            ->assertSessionHas('status', 'Rosa Lim is now Faculty. They can add the classes they teach under My classes.');

        $this->assertSame(UserRole::Faculty, $staff->fresh()->role);
        $this->actingAs($staff->fresh())->get(route('faculty.classes.index'))->assertOk();
    }

    #[TestWith([UserRole::Student, 'faculty'])]
    #[TestWith([UserRole::Admin, 'staff'])]
    #[TestWith([UserRole::Faculty, 'admin'])]
    public function test_the_switch_is_only_between_faculty_and_staff(UserRole $from, string $to): void
    {
        $account = User::factory()->create(['role' => $from]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->patch(route('admin.accounts.role', $account), ['role' => $to])
            ->assertStatus($from === UserRole::Faculty ? 302 : 422);

        $this->assertSame($from, $account->fresh()->role);
    }
}
