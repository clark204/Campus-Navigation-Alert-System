<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use App\Services\ContactDetails;
use App\Services\SystemStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin "Settings": one page with four tabs that each save on their own (see docs/settings.md). ?tab= picks the open
 * tab, so a reload or a save comes back to the same tab; resources/js/settings-tabs.js switches tabs without a reload.
 */
class SettingsController extends Controller
{
    /** Tab id (also its panel id and ?tab= value) => label. The first is the default. */
    public const TABS = [
        'contact-details' => 'Contact details',
        'emergency-contacts' => 'Emergency contacts',
        'my-account' => 'My account',
        'system-status' => 'System status',
    ];

    public function __invoke(Request $request, ContactDetails $contactDetails, SystemStatus $status): View
    {
        $tab = $request->query('tab');

        return view('admin.settings-page', [
            'tabs' => self::TABS,
            'activeTab' => is_string($tab) && array_key_exists($tab, self::TABS) ? $tab : array_key_first(self::TABS),
            'contact' => $contactDetails->all(),
            'emergencyContacts' => EmergencyContact::ordered(),
            'checks' => $status->checks($request),
        ]);
    }

    /**
     * The settings page with one tab open: where each section's form sends the admin back to.
     */
    public static function tabUrl(string $tab): string
    {
        return route('admin.settings', ['tab' => $tab]);
    }
}
