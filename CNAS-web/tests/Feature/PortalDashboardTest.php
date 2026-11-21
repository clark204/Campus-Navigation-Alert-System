<?php

namespace Tests\Feature;

use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PortalDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
    }

    /**
     * @return array<string, array{UserRole, string}>
     */
    public static function rolesAllowedIn(): array
    {
        return [
            'student in the student portal' => [UserRole::Student, 'student.dashboard'],
            'faculty in the faculty portal' => [UserRole::Faculty, 'faculty.dashboard'],
            'staff in the faculty portal' => [UserRole::Staff, 'faculty.dashboard'],
        ];
    }

    /**
     * @return array<string, array{UserRole, string}>
     */
    public static function rolesKeptOut(): array
    {
        return [
            'faculty in the student portal' => [UserRole::Faculty, 'student.dashboard'],
            'staff in the student portal' => [UserRole::Staff, 'student.dashboard'],
            'admin in the student portal' => [UserRole::Admin, 'student.dashboard'],
            'student in the faculty portal' => [UserRole::Student, 'faculty.dashboard'],
            'admin in the faculty portal' => [UserRole::Admin, 'faculty.dashboard'],
        ];
    }

    #[TestWith(['student.dashboard'])]
    #[TestWith(['faculty.dashboard'])]
    public function test_guests_are_sent_to_sign_in(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('rolesAllowedIn')]
    public function test_users_open_their_own_portal(UserRole $role, string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route($route))
            ->assertOk()
            ->assertSee('aria-label="Saved and recent places"', false)
            ->assertDontSee('Where do you need to go?');
    }

    #[DataProvider('rolesKeptOut')]
    public function test_users_are_forbidden_from_another_roles_portal(UserRole $role, string $route): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route($route))
            ->assertForbidden();
    }

    public function test_the_student_dashboard_shows_the_signed_in_student(): void
    {
        Room::factory()->create(['code' => 'r4', 'name' => 'ComLab 3', 'is_it' => true]);
        $student = User::factory()->create([
            'first_name' => 'Angelica',
            'last_name' => 'Dela Cruz',
            'school_id' => '20260412',
            'program_code' => 'BSIT',
            'role' => UserRole::Student,
        ]);
        $this->travelTo('2026-11-09 06:00:00'); // 2:00 PM campus time (Asia/Manila).

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Good afternoon, Angelica')
            ->assertSee('Student · College of Information Technology · 20260412')
            ->assertSee('Angelica Dela Cruz')
            ->assertSee('>AD</span>', false)
            ->assertSee('Student portal')
            ->assertSee('aria-current="page"', false)
            ->assertSee(route('navigate').'?to=r4', false);
    }

    public function test_my_college_lists_the_maps_it_rooms_only_for_it_college_users(): void
    {
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1', 'is_it' => true]);
        Room::factory()->create(['code' => 'r99', 'name' => 'Registrar Window', 'is_it' => false]);
        $itStudent = User::factory()->create(['program_code' => 'BSIT', 'role' => UserRole::Student]);
        $criminologyStudent = User::factory()->create(['program_code' => 'BSCRIM', 'role' => UserRole::Student]);

        $comLab1Directions = route('navigate').'?to=r6';

        $this->actingAs($itStudent)->get(route('student.dashboard'))
            ->assertSee('My college')
            ->assertSee($comLab1Directions, false)
            ->assertDontSee(route('navigate').'?to=r99', false);

        $this->actingAs($criminologyStudent)->get(route('student.dashboard'))
            ->assertSee('Student · College of Criminology')
            ->assertDontSee('My college')
            ->assertDontSee($comLab1Directions, false);
    }

    public function test_the_student_dashboard_hides_faculty_items(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.dashboard'))
            ->assertDontSee('Teaching today')
            ->assertDontSee('Classes');
    }

    public function test_the_faculty_dashboard_offers_my_classes_and_no_made_up_classes(): void
    {
        $faculty = User::factory()->create(['program_code' => 'BSIT', 'role' => UserRole::Faculty]);

        $this->actingAs($faculty)
            ->get(route('faculty.dashboard'))
            ->assertOk()
            ->assertSee('Faculty · College of Information Technology')
            ->assertSee('Faculty portal')
            ->assertSee('Add the classes you teach to see your next one and get directions to its room.')
            ->assertSee('href="'.route('faculty.classes.create').'"', false)
            ->assertSee('href="'.route('faculty.classes.index').'"', false)
            ->assertDontSee('Introduction to Computing')
            ->assertDontSee('Import from COR');
    }

    public function test_staff_get_no_teaching_parts(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))
            ->get(route('faculty.dashboard'))
            ->assertOk()
            ->assertDontSee('Next class')
            ->assertDontSee('My classes')
            ->assertDontSee(route('faculty.classes.index'), false);
    }

    public function test_campus_info_lists_the_maps_offices_and_no_made_up_announcements(): void
    {
        Room::factory()->create(['code' => 'r3', 'name' => 'DSAS Office', 'type' => RoomType::Office]);
        Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1', 'type' => RoomType::Lab]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.dashboard'))
            ->assertSeeInOrder(['Campus info', 'Offices', 'DSAS Office'])
            ->assertSee(route('navigate').'?to=r3', false)
            ->assertDontSee('Announcements')
            ->assertDontSee('8:00 AM – 5:00 PM');
    }

    public function test_staff_see_the_faculty_portal_under_their_own_role(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get(route('faculty.dashboard'))
            ->assertOk()
            ->assertSee('Faculty portal')
            ->assertSeeInOrder([$staff->full_name, 'Staff']);
    }

    public function test_the_sidebar_signs_the_user_out(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Student]))
            ->get(route('student.dashboard'))
            ->assertSee('<form method="POST" action="'.route('logout').'">', false)
            ->assertSee('Sign out');
    }
}
