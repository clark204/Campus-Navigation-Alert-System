<?php

namespace App\Services;

use App\Models\Room;
use App\Models\User;

/**
 * "Recent" places: the rooms a signed-in user last started directions to (Start on /navigate). Only the newest KEEP
 * are kept, so the list never grows into a location history.
 */
class RecentPlaces
{
    public const KEEP = 8;

    public function record(User $user, Room $room): void
    {
        $user->recentRooms()->syncWithoutDetaching([$room->id => ['used_at' => now()]]);

        $keep = $user->recentRooms()->limit(self::KEEP)->pluck('rooms.id');
        $user->recentRooms()->wherePivotNotIn('room_id', $keep)->detach();
    }

    public function clear(User $user): void
    {
        $user->recentRooms()->detach();
    }
}
