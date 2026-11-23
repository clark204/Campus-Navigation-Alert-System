<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SaveClassScheduleRequest;
use App\Http\Requests\Registration\UploadCorRequest;
use App\Http\Resources\ClassScheduleResource;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Services\ClassSchedules;
use App\Services\RegistrationService;
use App\Services\RoomMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The mobile app's "My schedule" (students) and "My classes" (faculty), like the web portal's ClassScheduleController
 * and ClassScheduleImportController: the same form request, services and policy (docs/schedule.md). Route middleware
 * picks who may change classes (students and faculty) and import a COR (students).
 */
class ScheduleController extends Controller
{
    public function __construct(private ClassSchedules $schedules) {}

    /**
     * GET /api/schedule: the user's classes for the current term, with the term's name in meta (null when none).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->list($request);
    }

    /**
     * The add/edit form's room list, like the web form: the fixed choices, then the map's rooms by building.
     */
    public function rooms(): JsonResponse
    {
        return response()->json([
            'choices' => [
                ['value' => SaveClassScheduleRequest::CHOICE_TBA, 'label' => 'Not announced yet'],
                ['value' => SaveClassScheduleRequest::CHOICE_ONLINE, 'label' => 'Online class'],
                ['value' => SaveClassScheduleRequest::CHOICE_OTHER, 'label' => 'Somewhere not on the map…'],
            ],
            'buildings' => Room::classRoomsByBuilding()
                ->map(fn ($rooms, string $building) => [
                    'name' => $building,
                    'rooms' => $rooms->map(fn (Room $room) => ['code' => $room->code, 'label' => $room->choiceLabel()])->values(),
                ])
                ->values(),
        ]);
    }

    public function store(SaveClassScheduleRequest $request, RoomMatcher $rooms): JsonResponse
    {
        $class = $this->schedules->add($request->user(), $request->classAttributes());

        if ($class === null) {
            return response()->json(['message' => ClassSchedules::NO_TERM_ADD_MESSAGE], 409);
        }

        $rooms->attach(collect([$class]));

        return (new ClassScheduleResource($class))
            ->additional(['message' => "{$class->subject} was added."])
            ->response()
            ->setStatusCode(201);
    }

    public function update(SaveClassScheduleRequest $request, ClassSchedule $classSchedule, RoomMatcher $rooms): ClassScheduleResource
    {
        $this->schedules->update($classSchedule, $request->classAttributes());
        $rooms->attach(collect([$classSchedule]));

        return (new ClassScheduleResource($classSchedule))->additional(['message' => "{$classSchedule->subject} was saved."]);
    }

    public function destroy(ClassSchedule $classSchedule): JsonResponse
    {
        Gate::authorize('delete', $classSchedule);

        $classSchedule->delete();

        return response()->json(['message' => "{$classSchedule->subject} was deleted."]);
    }

    /**
     * "Import from COR" (students): the same COR checks as registration, and it must be the student's own. The PDF is
     * deleted right after it is read. Answers with the new schedule.
     */
    public function import(UploadCorRequest $request, RegistrationService $registrations): AnonymousResourceCollection
    {
        $term = AcademicTerm::current();

        if ($term === null) {
            throw ValidationException::withMessages(['cor' => ClassSchedules::NO_TERM_MESSAGE]);
        }

        $cor = $registrations->acceptCor($request->file('cor'), $term);
        $result = $this->schedules->importFromCor($request->user(), $cor, $term);

        return $this->list($request, ClassSchedules::importMessage($result));
    }

    private function list(Request $request, ?string $message = null): AnonymousResourceCollection
    {
        return ClassScheduleResource::collection($this->schedules->forUser($request->user()))
            ->additional(array_filter([
                'meta' => ['term' => AcademicTerm::current()?->label()],
                'message' => $message,
            ], fn ($value) => $value !== null));
    }
}
