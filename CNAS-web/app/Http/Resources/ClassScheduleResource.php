<?php

namespace App\Http\Resources;

use App\Http\Requests\Portal\SaveClassScheduleRequest;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One class meeting for the mobile app. room_text is the room as written ("CompLab1", "Online", "TBA");
 * room_code and room_name are the matching room on the map (use room_code for directions), or null when the
 * text is not a room or is not on the map yet. Times are 24-hour campus time ("16:00"). room_choice and room_other
 * are what the edit form shows picked (SaveClassScheduleRequest::roomChoiceFor): send them back unchanged to keep the
 * room. Needs the `room` that RoomMatcher::attach sets.
 *
 * @mixin ClassSchedule
 */
class ClassScheduleResource extends JsonResource
{
    /**
     * @return array{id: int, subject: string, section: ?string, day: string, starts_at: string, ends_at: string, room_text: string, room_code: ?string, room_name: ?string, room_choice: string, room_other: ?string, source: string}
     */
    public function toArray(Request $request): array
    {
        $choice = SaveClassScheduleRequest::roomChoiceForMatched($this->resource, $this->room);

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
            'room_choice' => $choice['choice'],
            'room_other' => $choice['other'],
            'source' => $this->source->value,
        ];
    }
}
