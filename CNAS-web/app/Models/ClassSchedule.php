<?php

namespace App\Models;

use App\Enums\ScheduleSource;
use App\Enums\Weekday;
use Database\Factories\ClassScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One class meeting of a student in one academic term. Read through App\Services\ClassSchedules, which shows only
 * the current term and matches the room.
 *
 * @property int $user_id
 * @property int $academic_term_id
 * @property string $subject e.g. "IT 119"
 * @property string|null $section
 * @property Weekday $day
 * @property string $starts_at 24-hour "H:i"
 * @property string $ends_at 24-hour "H:i"
 * @property string $room_text The room exactly as written: "CompLab1", "Online", "TBA".
 * @property ScheduleSource $source
 * @property-read Room|null $room The room on the map, set by RoomMatcher::attach(). Not a database relation.
 */
#[Fillable(['academic_term_id', 'subject', 'section', 'day', 'starts_at', 'ends_at', 'room_text', 'source'])]
class ClassSchedule extends Model
{
    /** @use HasFactory<ClassScheduleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => Weekday::class,
            'source' => ScheduleSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * MySQL returns a TIME as "16:00:00"; the app always works with "16:00".
     *
     * @return Attribute<string, never>
     */
    protected function startsAt(): Attribute
    {
        return Attribute::get(fn (string $value): string => substr($value, 0, 5));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function endsAt(): Attribute
    {
        return Attribute::get(fn (string $value): string => substr($value, 0, 5));
    }

    /**
     * "4:00 – 7:00 PM", or "10:00 AM – 1:00 PM" when it crosses noon.
     */
    public function timeLabel(): string
    {
        $start = Carbon::createFromFormat('H:i', $this->starts_at);
        $end = Carbon::createFromFormat('H:i', $this->ends_at);

        return $start->format($start->format('A') === $end->format('A') ? 'g:i' : 'g:i A').' – '.$end->format('g:i A');
    }
}
