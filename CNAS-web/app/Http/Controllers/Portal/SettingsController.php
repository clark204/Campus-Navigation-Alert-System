<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal "Settings" (students and faculty): the same tabbed page as the admin's (see docs/portal.md). ?tab= picks the
 * open tab; resources/js/settings-tabs.js switches tabs without a reload.
 */
class SettingsController extends Controller
{
    /** Tab id (also its panel id and ?tab= value) => label. The first is the default. */
    public const TABS = [
        'profile' => 'Profile',
        'my-account' => 'Account',
        'alerts' => 'Alerts',
    ];

    public function __invoke(Request $request): View
    {
        $user = $request->user()->loadMissing('program.college');
        $tab = $request->query('tab');

        return view('portal.settings-page', [
            'role' => $user->role->portal(),
            'user' => $user,
            'tabs' => self::TABS,
            'activeTab' => is_string($tab) && array_key_exists($tab, self::TABS) ? $tab : array_key_first(self::TABS),
        ]);
    }
}
