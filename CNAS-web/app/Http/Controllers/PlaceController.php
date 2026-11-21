<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RecentPlaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saved and recent places of the signed-in user. /navigate calls these with fetch (JSON); the Saved places page
 * uses plain forms (redirect back with a message).
 */
class PlaceController extends Controller
{
    public function save(Request $request, Room $room): JsonResponse|RedirectResponse
    {
        $request->user()->savedRooms()->syncWithoutDetaching([$room->id]);

        return $this->reply($request, true, "{$room->name} was saved.");
    }

    public function unsave(Request $request, Room $room): JsonResponse|RedirectResponse
    {
        $request->user()->savedRooms()->detach($room->id);

        return $this->reply($request, false, "{$room->name} was removed from your saved places.");
    }

    /**
     * Start on /navigate: the room goes to the top of Recent.
     */
    public function recent(Request $request, Room $room, RecentPlaces $recentPlaces): Response
    {
        $recentPlaces->record($request->user(), $room);

        return response()->noContent();
    }

    public function clearRecent(Request $request, RecentPlaces $recentPlaces): RedirectResponse
    {
        $recentPlaces->clear($request->user());

        return back()->with('status', 'Recent places cleared.');
    }

    private function reply(Request $request, bool $saved, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['saved' => $saved, 'message' => $message])
            : back()->with('status', $message);
    }
}
