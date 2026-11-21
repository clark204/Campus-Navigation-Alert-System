<?php

namespace App\Http\Resources;

use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One class meeting for the mobile app. room_text is the room as written ("CompLab1", "Online", "TBA");
 * room_code and room_name are the matching room on the map (use room_code for directions), or null when the
 * text is not a room or is not on the map yet. Times are 24-hour campus time ("16:00").
 *
 * @mixin ClassSchedule
 */
class ClassScheduleResource extends JsonResource
{
    /**
     * @return array{id: int, subject: string, section: ?string, day: string, starts_at: string, ends_at: string, room_text: string, room_code: ?string, room_name: ?string, source: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'section' => $this->section,
            'day' => $this->day->value,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'room_text' => $this->room_text,
            'room_code' => $this->room?->code,
            'room_name' => $this->room?->name,
            'source' => $this->source->value,
        ];
    }
}
