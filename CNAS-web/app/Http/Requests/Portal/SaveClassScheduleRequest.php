<?php

namespace App\Http\Requests\Portal;

use App\Enums\Weekday;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Services\RoomMatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing one class on "My schedule" (students) or "My classes" (faculty). When editing, the class must be
 * the user's own.
 *
 * The room is picked from a list (room_choice): "Not announced yet" (TBA), "Online", a room on the map (its code, so
 * the class stays linked when the admin renames the room), or "Somewhere not on the map" with the place typed in
 * room_other (a gym or a field). classAttributes() turns that into the stored room_text.
 */
class SaveClassScheduleRequest extends FormRequest
{
    public const CHOICE_TBA = 'TBA';

    public const CHOICE_ONLINE = 'Online';

    public const CHOICE_OTHER = 'other';

    public function authorize(): bool
    {
        $class = $this->route('classSchedule');

        return $class === null || $this->user()->can('update', $class);
    }

    /**
     * Seconds a browser may add to a time ("16:00:00") are dropped. No choice means "Not announced yet".
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'room_choice' => filled($this->room_choice) ? (string) $this->room_choice : self::CHOICE_TBA,
            'room_other' => is_string($this->room_other) ? trim($this->room_other) : $this->room_other,
            'starts_at' => is_string($this->starts_at) ? substr($this->starts_at, 0, 5) : $this->starts_at,
            'ends_at' => is_string($this->ends_at) ? substr($this->ends_at, 0, 5) : $this->ends_at,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:30'],
            'day' => ['required', Rule::enum(Weekday::class)],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'room_choice' => ['required', 'string', Rule::in([
                self::CHOICE_TBA, self::CHOICE_ONLINE, self::CHOICE_OTHER, ...Room::query()->classRooms()->pluck('code')->all(),
            ])],
            'room_other' => ['nullable', 'required_if:room_choice,'.self::CHOICE_OTHER, 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'Enter the subject, for example IT 119.',
            'subject.max' => 'Keep the subject to 50 characters or fewer.',
            'section.max' => 'Keep the section to 30 characters or fewer.',
            'day.required' => 'Choose the day of the class.',
            'day.enum' => 'Choose the day of the class.',
            'starts_at.required' => 'Enter the time the class starts.',
            'starts_at.date_format' => 'Enter the time the class starts.',
            'ends_at.required' => 'Enter the time the class ends.',
            'ends_at.date_format' => 'Enter the time the class ends.',
            'ends_at.after' => 'The class must end after it starts.',
            'room_choice.in' => 'Choose a room from the list.',
            'room_other.required_if' => 'Type where the class meets, for example Gym.',
            'room_other.max' => 'Keep the place to 100 characters or fewer.',
        ];
    }

    /**
     * The class as stored: the form's fields with the room choice turned into room_text.
     *
     * @return array{subject: string, section: ?string, day: string, starts_at: string, ends_at: string, room_text: string}
     */
    public function classAttributes(): array
    {
        $choice = $this->validated('room_choice');

        return [
            ...$this->safe()->only(['subject', 'section', 'day', 'starts_at', 'ends_at']),
            'room_text' => $choice === self::CHOICE_OTHER ? $this->validated('room_other') : $choice,
        ];
    }

    /**
     * What the room list shows selected for a class: "Not announced yet" for a new class or TBA, "Online", the
     * matched room's code, or "Somewhere not on the map" with the text as written (e.g. "FIELD" from a COR).
     *
     * @return array{choice: string, other: ?string}
     */
    public static function roomChoiceFor(?ClassSchedule $class, RoomMatcher $rooms): array
    {
        return self::roomChoiceForMatched($class, $class === null ? null : $rooms->match($class->room_text));
    }

    /**
     * roomChoiceFor() with the class's room already matched (the `room` that RoomMatcher::attach sets).
     *
     * @return array{choice: string, other: ?string}
     */
    public static function roomChoiceForMatched(?ClassSchedule $class, ?Room $room): array
    {
        if ($class === null || RoomMatcher::normalize($class->room_text) === 'tba') {
            return ['choice' => self::CHOICE_TBA, 'other' => null];
        }
        if (RoomMatcher::normalize($class->room_text) === 'online') {
            return ['choice' => self::CHOICE_ONLINE, 'other' => null];
        }

        return $room ? ['choice' => $room->code, 'other' => null] : ['choice' => self::CHOICE_OTHER, 'other' => $class->room_text];
    }
}
