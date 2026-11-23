<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/places: the user's saved places (newest first) and recent places (the newest RecentPlaces::KEEP), like the
 * portal's Saved places page. Saving, removing, Recent and clearing it use the web's PlaceController, which answers
 * JSON to the app (docs/portal.md).
 */
class PlaceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'saved' => PlaceResource::collection($user->savedRooms()->with('building')->get()),
            'recent' => PlaceResource::collection($user->recentRooms()->with('building')->get()),
        ]);
    }
}
