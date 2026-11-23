<?php

namespace App\Http\Controllers\Portal;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\UploadCorRequest;
use App\Models\AcademicTerm;
use App\Services\ClassSchedules;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Student portal "Import from COR": the COR is checked exactly as at registration, must be the signed-in
 * student's, and replaces their COR rows for the current term. The PDF is deleted right after it is read.
 */
class ClassScheduleImportController extends Controller
{
    public function create(): View
    {
        return view('portal.schedule-import-page', ['role' => UserRole::Student, 'term' => AcademicTerm::current()]);
    }

    public function store(UploadCorRequest $request, RegistrationService $registrations, ClassSchedules $schedules): RedirectResponse
    {
        $term = AcademicTerm::current();

        if ($term === null) {
            throw ValidationException::withMessages(['cor' => ClassSchedules::NO_TERM_MESSAGE]);
        }

        $cor = $registrations->acceptCor($request->file('cor'), $term);
        $result = $schedules->importFromCor($request->user(), $cor, $term);

        return to_route('student.schedule.index')->with('status', ClassSchedules::importMessage($result));
    }
}
