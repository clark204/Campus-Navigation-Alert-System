<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BuildingType;
use App\Enums\PathType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Alert;
use App\Models\Building;
use App\Models\LoginActivity;
use App\Models\Path;
use App\Models\Room;
use App\Models\Stair;
use App\Models\User;
use App\Services\AdminAnalytics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Online users listed by name; the rest are only counted. */
    private const ONLINE_LIST_LIMIT = 10;

    private const SIGN_INS_PER_PAGE = 10;

    private const RECENT_SIGN_UPS = 5;

    public function __invoke(Request $request, AdminAnalytics $analytics): View
    {
        // Filters for "User activity". Unknown roles are ignored rather than rejected: it's only a filter.
        $role = UserRole::tryFrom((string) $request->query('role'));
        $search = Str::limit(trim((string) $request->query('q')), 100, '');
        $filterUsers = fn (Builder $users): Builder => $users
            ->when($role, fn (Builder $users) => $users->where('role', $role))
            ->when($search !== '', fn (Builder $users) => $users->search($search));
        $isFiltered = $role !== null || $search !== '';

        return view('admin.dashboard-page', [
            'activeAlert' => Alert::active()->latest('sent_at')->first(),
            'termWarning' => AcademicTerm::warning(),
            // The analytics (tiles, sign-ins per day, accounts, colleges, concerns, alerts, places): the app's too.
            'analytics' => $analytics->summary(),
            // Campus map: what the map tables hold (the campus outline, facilities, the evacuation area and gates
            // are not counted).
            'mapCounts' => [
                'Buildings' => Building::where('type', BuildingType::Building)->count(),
                'Rooms' => Room::count(),
                'Walkways' => Path::where('type', PathType::Walkway)->where('floor', 1)->count(),
                'Stairs' => Stair::count(),
            ],
            // Admin accounts come from the seeder, not sign-up.
            'recentSignUps' => User::query()
                ->where('role', '!=', UserRole::Admin)
                ->with('college')
                ->latest()
                ->latest('id')
                ->limit(self::RECENT_SIGN_UPS)
                ->get(),
            'activityFilters' => ['role' => $role, 'q' => $search, 'active' => $isFiltered],
            'onlineCount' => User::online()->tap($filterUsers)->count(),
            'onlineUsers' => User::online()
                ->tap($filterUsers)
                ->with('latestLoginActivity')
                ->orderByDesc('last_seen_at')
                ->limit(self::ONLINE_LIST_LIMIT)
                ->get(),
            'signIns' => LoginActivity::query()
                ->when($isFiltered, fn (Builder $signIns) => $signIns->whereHas('user', $filterUsers))
                ->with('user')
                ->orderByDesc('logged_in_at')
                ->orderByDesc('id')
                ->paginate(self::SIGN_INS_PER_PAGE)
                ->withQueryString()
                ->fragment('user-activity'),
        ]);
    }
}
