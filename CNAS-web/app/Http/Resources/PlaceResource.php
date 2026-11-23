<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved or recent place for the mobile app: the room's code (its place id in navigation, /navigate?to=<code>), name
 * and where it is ("NEE Building · 2nd floor", Room::locationLabel). Needs `building` loaded.
 *
 * @mixin Room
 */
class PlaceResource extends JsonResource
{
    /**
     * @return array{code: string, name: string, location: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->locationLabel(),
        ];
    }
}
