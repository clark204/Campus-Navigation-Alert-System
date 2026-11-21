<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use App\Notifications\ConcernReplied;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal "Help & contact" (students and faculty): send a concern to Admin > Concerns, see the status of the ones
 * already sent with the administrator's replies, and the administrator's contact details and emergency numbers.
 */
class HelpController extends Controller
{
    /** The user's own concerns listed under "Your messages", newest first. */
    public const RECENT_CONCERNS = 5;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $concerns = $user->concerns()->with('replies')->latest()->latest('id')->limit(self::RECENT_CONCERNS)->get();

        // The replies are on this page now, so their bell notifications are read.
        $user->unreadNotifications()->where('type', ConcernReplied::class)->update(['read_at' => now()]);

        return view('portal.help-page', [
            'role' => $user->role->portal(),
            'user' => $user,
            'concerns' => $concerns,
            'emergencyContacts' => EmergencyContact::listed(),
        ]);
    }
}
