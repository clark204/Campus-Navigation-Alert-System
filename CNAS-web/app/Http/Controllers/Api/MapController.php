<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CampusMap;
use Illuminate\Http\JsonResponse;

/**
 * The campus map for every map on the site and the mobile app: buildings, paths, rooms ("entrances"), stairs and
 * the friendly names used in walking directions. Open to guests, like /navigate. The route adds an ETag, so a
 * client that already has the current map gets a 304.
 */
class MapController extends Controller
{
    public function __invoke(CampusMap $map): JsonResponse
    {
        $payload = $map->payload();

        // Objects, so a map without landmark names is sent as {} rather than [].
        $payload['landmarks'] = array_map(fn (array $names) => (object) $names, $payload['landmarks']);

        return response()->json(['data' => $payload]);
    }
}
