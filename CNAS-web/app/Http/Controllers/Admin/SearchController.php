<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin top bar search: suggestions while typing (resources/js/admin-search.js) and the full results page, which
 * Enter opens and which works without JavaScript. Both use App\Services\AdminSearch.
 */
class SearchController extends Controller
{
    public function index(Request $request, AdminSearch $search): View
    {
        $query = $this->query($request);

        return view('admin.search-page', [
            'query' => $query,
            'groups' => $search->search($query, AdminSearch::PAGE_LIMIT),
            'tooShort' => mb_strlen($query) < AdminSearch::MIN_LENGTH,
        ]);
    }

    public function suggestions(Request $request, AdminSearch $search): JsonResponse
    {
        $query = $this->query($request);

        return response()->json([
            'query' => $query,
            'groups' => $search->search($query),
            'all_url' => route('admin.search', ['q' => $query]),
        ])->header('Cache-Control', 'no-store');
    }

    private function query(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q')), 0, AdminSearch::MAX_LENGTH);
    }
}
