<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Semester;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAcademicTermRequest;
use App\Models\AcademicTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admins choose the current academic term; students can only register with a COR for it.
 */
class AcademicTermController extends Controller
{
    public function index(): View
    {
        return view('admin.academic-terms-page', [
            'currentTerm' => AcademicTerm::current(),
            'terms' => AcademicTerm::orderByDesc('academic_year')->orderByDesc('semester')->get(),
            'semesters' => Semester::cases(),
        ]);
    }

    public function store(StoreAcademicTermRequest $request): RedirectResponse
    {
        $term = AcademicTerm::create($request->validated());

        return redirect()->route('admin.academic-terms.index')
            ->with('status', "{$term->label()} was added. It isn't current until you set it.");
    }

    public function makeCurrent(AcademicTerm $academicTerm): RedirectResponse
    {
        $academicTerm->makeCurrent();

        return redirect()->route('admin.academic-terms.index')
            ->with('status', "{$academicTerm->label()} is now the current term.");
    }
}
