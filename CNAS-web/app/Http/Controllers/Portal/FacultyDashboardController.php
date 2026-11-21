<?php

namespace App\Http\Controllers\Portal;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\ClassSchedules;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The faculty portal's Home, shared by faculty and staff. Faculty also get their next class and today's classes from
 * "My classes" (added by hand); staff don't teach, so their Home has no teaching parts at all.
 */
class FacultyDashboardController extends Controller
{
    public function __invoke(Request $request, ClassSchedules $schedules): View
    {
        $user = $request->user();
        $teaches = $user->role === UserRole::Faculty;
        $classes = $teaches ? $schedules->forUser($user) : collect();

        return view('portal.dashboard-page', [
            'role' => UserRole::Faculty,
            'teaches' => $teaches,
            'hasClasses' => $classes->isNotEmpty(),
            'nextClass' => $teaches ? $schedules->next($classes, now()) : null,
            'todayClasses' => $schedules->today($classes, now()),
        ]);
    }
}
