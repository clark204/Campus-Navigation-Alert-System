<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Services\AdminAnalytics;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/dashboard: the app's admin dashboard, the same numbers as the website's (AdminAnalytics), plus the
 * academic term warning (AcademicTerm::warning).
 */
class DashboardController extends Controller
{
    public function __invoke(AdminAnalytics $analytics): JsonResponse
    {
        return response()->json([...$analytics->summary(), 'term_warning' => AcademicTerm::warning()])->header('Cache-Control', 'no-store');
    }
}
