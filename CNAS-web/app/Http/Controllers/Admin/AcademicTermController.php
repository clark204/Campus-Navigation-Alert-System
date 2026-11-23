<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAcademicTermRequest;
use App\Models\AcademicTerm;
use Illuminate\Http\RedirectResponse;

/**
 * Settings → Academic term (docs/registration.md): admins add terms and choose the current one; students can only
 * register with a COR for it. The section lives on the Settings page; the old /admin/academic-terms page redirects
 * there. The mobile app uses the same request and messages (Api\Admin\SettingsController).
 */
class AcademicTermController extends Controller
{
    /** The old page's URL, kept for bookmarks: Settings opens on the Academic term tab. */
    public function index(): RedirectResponse
    {
        return redirect(SettingsController::tabUrl('academic-term'));
    }

    public function store(StoreAcademicTermRequest $request): RedirectResponse
    {
        $term = AcademicTerm::create($request->validated());

        return $this->back(self::addedMessage($term));
    }

    public function makeCurrent(AcademicTerm $academicTerm): RedirectResponse
    {
        $academicTerm->makeCurrent();

        return $this->back(self::currentMessage($academicTerm));
    }

    public static function addedMessage(AcademicTerm $term): string
    {
        return "{$term->label()} was added. It isn't current until you set it.";
    }

    public static function currentMessage(AcademicTerm $term): string
    {
        return "{$term->label()} is now the current term.";
    }

    private function back(string $status): RedirectResponse
    {
        return redirect(SettingsController::tabUrl('academic-term'))->with('section', 'term')->with('status', $status);
    }
}
