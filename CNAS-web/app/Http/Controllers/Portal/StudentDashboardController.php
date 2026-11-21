<?php

namespace App\Http\Controllers\Portal;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\ClassSchedules;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The student portal's Home: the shared portal dashboard plus the student's next class and today's classes.
 */
class StudentDashboardController extends Controller
{
    public function __invoke(Request $request, ClassSchedules $schedules): View
    {
        $classes = $schedules->forUser($request->user());

        return view('portal.dashboard-page', [
            'role' => UserRole::Student,
            'hasClasses' => $classes->isNotEmpty(),
            'nextClass' => $schedules->next($classes, now()),
            'todayClasses' => $schedules->today($classes, now()),
        ]);
    }
}
