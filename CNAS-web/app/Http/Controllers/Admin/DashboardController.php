<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Remind the admin to check the term once it has been current this long. */
    private const TERM_REMINDER_MONTHS = 5;

    public function __invoke(): View
    {
        $currentTerm = AcademicTerm::current();

        return view('admin.dashboard-page', [
            'currentTerm' => $currentTerm,
            'termMayBeOutdated' => $currentTerm?->set_current_at?->lt(now()->subMonths(self::TERM_REMINDER_MONTHS)) ?? false,
        ]);
    }
}
