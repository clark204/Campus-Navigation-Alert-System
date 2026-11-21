<?php

namespace App\Services;

use App\Models\ClassSchedule;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Finds the room on the map for a room written on a schedule ("CompLab1" is the room named "ComLab 1").
 *
 * Schedules store only the text. It is matched each time a schedule is read, so a room added to the map later
 * starts working without importing the COR again.
 */
class RoomMatcher
{
    /** Written in the Room column, but not places: shown as written, with no directions. */
    private const NOT_ROOMS = ['online', 'tba'];

    /** @var array<string, Room>|null Rooms by normalised name and code, loaded once. */
    private ?array $rooms = null;

    /**
     * False for "Online" and "TBA" (any case).
     */
    public static function isRoom(string $roomText): bool
    {
        return ! in_array(self::normalize($roomText), self::NOT_ROOMS, true);
    }

    /**
     * Lower case, no spaces or punctuation, and "complab" spelled "comlab": "CompLab1", "COMLAB 1" and
     * "ComLab 1" are all "comlab1".
     */
    public static function normalize(string $text): string
    {
        return str_replace('complab', 'comlab', preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '');
    }

    /**
     * The room with this name or code, or null when it is not on the map (or is not a room at all).
     */
    public function match(string $roomText): ?Room
    {
        $key = self::normalize($roomText);

        if ($key === '' || ! self::isRoom($roomText)) {
            return null;
        }

        return $this->rooms()[$key] ?? null;
    }

    /**
     * Set each class's `room` (the matched Room or null), for the views and the API.
     *
     * @param  Collection<int, ClassSchedule>  $classes
     * @return Collection<int, ClassSchedule>
     */
    public function attach(Collection $classes): Collection
    {
        return $classes->each(fn (ClassSchedule $class) => $class->setRelation('room', $this->match($class->room_text)));
    }

    /**
     * A name wins over a code when both normalise to the same text.
     *
     * @return array<string, Room>
     */
    private function rooms(): array
    {
        if ($this->rooms !== null) {
            return $this->rooms;
        }

        $rooms = Room::query()->orderBy('id')->get(['id', 'code', 'name']);
        $byCode = $rooms->keyBy(fn (Room $room) => self::normalize($room->code));
        // Reversed, so that of two rooms with the same name the first one is kept.
        $byName = $rooms->reverse()->keyBy(fn (Room $room) => self::normalize($room->name));

        return $this->rooms = [...$byCode->all(), ...$byName->all()];
    }
}
