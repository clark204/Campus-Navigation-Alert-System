<?php

namespace App\Http\Controllers\Portal;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SaveClassScheduleRequest;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Services\ClassSchedules;
use App\Services\RoomMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The week view of one person's classes, and adding, editing and deleting one class: the student portal's "My
 * schedule" (student.schedule.*, which also has the COR import) and the faculty portal's "My classes"
 * (faculty.classes.*, faculty only: staff don't teach, and there is no COR for teachers, so classes are added by hand).
 * Route middleware picks who may come in; everyone reaches only their own rows (ClassSchedulePolicy).
 */
class ClassScheduleController extends Controller
{
    public function index(Request $request, ClassSchedules $schedules): View
    {
        $classes = $schedules->forUser($request->user());

        return view('portal.schedule-page', [
            ...$this->portal($request),
            'term' => AcademicTerm::current(),
            'hasClasses' => $classes->isNotEmpty(),
            // Monday to Saturday always; Sunday only when it has a class.
            'days' => collect(Weekday::cases())
                ->mapWithKeys(fn (Weekday $day) => [$day->value => $classes->where('day', $day)->values()])
                ->reject(fn ($dayClasses, string $day) => $day === Weekday::Sun->value && $dayClasses->isEmpty()),
            'today' => Weekday::fromDate(now(ClassSchedules::TIMEZONE)),
        ]);
    }

    public function create(Request $request, RoomMatcher $rooms): View
    {
        return view('portal.schedule-form-page', [...$this->portal($request), ...$this->roomList(null, $rooms), 'class' => null]);
    }

    public function store(SaveClassScheduleRequest $request, ClassSchedules $schedules): RedirectResponse
    {
        $index = $this->portal($request)['scheduleRoute'].'.index';
        $class = $schedules->add($request->user(), $request->classAttributes());

        if ($class === null) {
            return to_route($index)->with('warning', ClassSchedules::NO_TERM_ADD_MESSAGE);
        }

        return to_route($index)->with('status', "{$class->subject} was added.");
    }

    public function edit(Request $request, ClassSchedule $classSchedule, RoomMatcher $rooms): View
    {
        Gate::authorize('update', $classSchedule);

        return view('portal.schedule-form-page', [...$this->portal($request), ...$this->roomList($classSchedule, $rooms), 'class' => $classSchedule]);
    }

    /**
     * A COR row the student really changes becomes a manual row (ClassSchedules::update).
     */
    public function update(SaveClassScheduleRequest $request, ClassSchedule $classSchedule, ClassSchedules $schedules): RedirectResponse
    {
        $schedules->update($classSchedule, $request->classAttributes());

        return to_route($this->portal($request)['scheduleRoute'].'.index')->with('status', "{$classSchedule->subject} was saved.");
    }

    public function destroy(Request $request, ClassSchedule $classSchedule): RedirectResponse
    {
        Gate::authorize('delete', $classSchedule);

        $classSchedule->delete();

        return to_route($this->portal($request)['scheduleRoute'].'.index')->with('status', "{$classSchedule->subject} was deleted.");
    }

    /**
     * The room list for the form (Room::classRoomsByBuilding), and what is selected for this class
     * (SaveClassScheduleRequest::roomChoiceFor).
     *
     * @return array{roomsByBuilding: Collection<string, Collection<int, Room>>, roomChoice: array{choice: string, other: ?string}}
     */
    private function roomList(?ClassSchedule $class, RoomMatcher $rooms): array
    {
        return [
            'roomsByBuilding' => Room::classRoomsByBuilding(),
            'roomChoice' => SaveClassScheduleRequest::roomChoiceFor($class, $rooms),
        ];
    }

    /**
     * Which portal this request is in, from its route name: the layout's role, the routes' prefix (student.schedule or
     * faculty.classes), the page name, and whether the COR import is offered (students only).
     *
     * @return array{role: UserRole, scheduleRoute: string, scheduleName: string, isStudent: bool}
     */
    private function portal(Request $request): array
    {
        $isStudent = $request->routeIs('student.*');

        return [
            'role' => $isStudent ? UserRole::Student : UserRole::Faculty,
            'scheduleRoute' => $isStudent ? 'student.schedule' : 'faculty.classes',
            'scheduleName' => $isStudent ? 'My schedule' : 'My classes',
            'isStudent' => $isStudent,
        ];
    }
}
