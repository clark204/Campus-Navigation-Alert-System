<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportCampusMapRequest;
use App\Services\CampusMapFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Campus map data is edited in another app. Here it is downloaded as campus.geojson and imported back.
 */
class CampusMapController extends Controller
{
    public function index(): View
    {
        return view('admin.map-data-page');
    }

    public function export(CampusMapFile $file): Response
    {
        return response($file->export())
            ->header('Content-Type', 'application/geo+json')
            ->header('Content-Disposition', 'attachment; filename="campus.geojson"');
    }

    public function import(ImportCampusMapRequest $request, CampusMapFile $file): RedirectResponse
    {
        try {
            $report = $file->import($request->file('file')->get());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return to_route('admin.map.index')
            ->with('status', $this->summary($report))
            ->with('importReport', $report);
    }

    /**
     * The one-line message. The full result (by name) is listed on the page.
     *
     * @param  array{
     *     added: list<array<string, string>>,
     *     updated: list<array<string, string>>,
     *     matched: list<array<string, string>>,
     *     duplicates: list<array<string, string>>,
     *     kept: list<array<string, string>>,
     * }  $report
     */
    private function summary(array $report): string
    {
        $added = count($report['added']) + count($report['duplicates']);
        $updated = count($report['updated']) + count($report['matched']);
        $summary = "Added {$added}, updated {$updated}.";

        $kept = count($report['kept']);

        return $kept === 0 ? $summary : $summary." {$kept} ".($kept === 1 ? 'is' : 'are').' kept because not in the file.';
    }
}
