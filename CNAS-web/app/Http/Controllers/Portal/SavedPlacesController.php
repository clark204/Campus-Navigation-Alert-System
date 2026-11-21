<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Portal "Saved places": every room the user saved, and their recent places, with directions, remove and clear.
 */
class SavedPlacesController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('portal.saved-places-page', [
            'role' => $user->role->portal(),
            'savedPlaces' => $user->savedRooms()->with('building')->get(),
            'recentPlaces' => $user->recentRooms()->with('building')->get(),
        ]);
    }
}
