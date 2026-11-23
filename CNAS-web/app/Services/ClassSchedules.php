<?php

namespace App\Services;

use App\Enums\ScheduleSource;
use App\Enums\Weekday;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\User;
use App\Services\Cor\ParsedCor;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A student's class schedule (or a teacher's classes): reading it (current term only, rooms matched to the map),
 * working out the next class, adding and editing one class, and filling it from a COR. Shared by the web portal and
 * the mobile app's API.
 */
class ClassSchedules
{
    /** Class times are campus times. The app itself runs on UTC. */
    public const TIMEZONE = 'Asia/Manila';

    public const WRONG_STUDENT_MESSAGE = 'This COR belongs to another student. Please upload your own COR.';

    public const NO_TERM_MESSAGE = 'There is no current term yet, so a schedule cannot be imported. Please try again later.';

    public const NO_TERM_ADD_MESSAGE = 'There is no current term yet, so classes cannot be added.';

    public function __construct(private RoomMatcher $rooms) {}

    /**
     * The student's classes this term, Monday first and by start time, each with its `room` (or null).
     * Empty when there is no current term.
     *
     * @return Collection<int, ClassSchedule>
     */
    public function forUser(User $user): Collection
    {
        $term = AcademicTerm::current();

        if ($term === null) {
            return new Collection;
        }

        $classes = $user->classSchedules()
            ->where('academic_term_id', $term->id)
            ->get()
            ->sortBy(fn (ClassSchedule $class) => [$class->day->number(), $class->starts_at, $class->subject])
            ->values();

        return $this->rooms->attach($classes);
    }

    /**
     * Today's classes on campus time, in order.
     *
     * @param  Collection<int, ClassSchedule>  $classes
     * @return Collection<int, ClassSchedule>
     */
    public function today(Collection $classes, CarbonInterface $now): Collection
    {
        $today = Weekday::fromDate($now->copy()->setTimezone(self::TIMEZONE));

        return $classes->filter(fn (ClassSchedule $class) => $class->day === $today)->sortBy('starts_at')->values();
    }

    /**
     * The class to go to next: the first one today that has not ended (so a class in progress counts), otherwise
     * the first one on the next day that has classes, up to the same day next week.
     *
     * @param  Collection<int, ClassSchedule>  $classes
     * @return array{class: ClassSchedule, when: string}|null `when` is "Now", "Today", "Tomorrow" or a day name.
     */
    public function next(Collection $classes, CarbonInterface $now): ?array
    {
        $now = $now->copy()->setTimezone(self::TIMEZONE);
        $time = $now->format('H:i');

        for ($daysAhead = 0; $daysAhead <= 7; $daysAhead++) {
            $day = Weekday::fromDate($now->copy()->addDays($daysAhead));

            $class = $classes
                ->filter(fn (ClassSchedule $class) => $class->day === $day && ($daysAhead > 0 || $class->ends_at > $time))
                ->sortBy('starts_at')
                ->first();

            if ($class !== null) {
                return [
                    'class' => $class,
                    'when' => match (true) {
                        $daysAhead === 0 => $class->starts_at <= $time ? 'Now' : 'Today',
                        $daysAhead === 1 => 'Tomorrow',
                        default => $day->label(),
                    },
                ];
            }
        }

        return null;
    }

    /**
     * Add a class by hand to the current term (SaveClassScheduleRequest::classAttributes). Null when there is no
     * current term.
     *
     * @param  array{subject: string, section: ?string, day: string, starts_at: string, ends_at: string, room_text: string}  $attributes
     */
    public function add(User $user, array $attributes): ?ClassSchedule
    {
        $term = AcademicTerm::current();

        if ($term === null) {
            return null;
        }

        return $user->classSchedules()->create([
            ...$attributes,
            'academic_term_id' => $term->id,
            'source' => ScheduleSource::Manual,
        ]);
    }

    /**
     * Save an edit. A COR row the student really changes becomes a manual row, so a later import keeps the change;
     * saving it unchanged keeps the COR text and source.
     *
     * @param  array{subject: string, section: ?string, day: string, starts_at: string, ends_at: string, room_text: string}  $attributes
     */
    public function update(ClassSchedule $class, array $attributes): void
    {
        // The same room picked again keeps its text as written ("CompLab1" from a COR is the room r6): not a change.
        if ($this->rooms->match($attributes['room_text'])?->is($this->rooms->match($class->room_text))) {
            $attributes['room_text'] = $class->room_text;
        }

        // Compared as shown, not with isDirty(): the database's "16:00:00" is the form's "16:00".
        $changed = collect($attributes)->contains(function (?string $value, string $field) use ($class): bool {
            $current = $class->getAttribute($field);

            return (string) ($current instanceof Weekday ? $current->value : $current) !== (string) $value;
        });

        if ($changed) {
            $class->fill([...$attributes, 'source' => ScheduleSource::Manual])->save();
        }
    }

    /**
     * "15 classes imported from your COR. 2 classes you changed by hand were kept."
     *
     * @param  array{imported: int, kept: int}  $result
     */
    public static function importMessage(array $result): string
    {
        $message = $result['imported'].' '.Str::plural('class', $result['imported']).' imported from your COR.';

        if ($result['kept'] > 0) {
            $message .= ' '.$result['kept'].' '.Str::plural('class', $result['kept']).' you changed by hand '.($result['kept'] === 1 ? 'was' : 'were').' kept.';
        }

        return $message;
    }

    /**
     * "Import from COR": the COR must be the student's own. See replaceCorRows().
     *
     * @return array{imported: int, kept: int}
     *
     * @throws ValidationException (key "cor")
     */
    public function importFromCor(User $user, ParsedCor $cor, AcademicTerm $term): array
    {
        if ($cor->studentId !== $user->school_id) {
            throw ValidationException::withMessages(['cor' => self::WRONG_STUDENT_MESSAGE]);
        }

        return $this->replaceCorRows($user, $term, $cor->meetings());
    }

    /**
     * Replace the student's COR rows for the term with these meetings. Manual rows (added or changed by hand) are
     * left alone, and a COR meeting is skipped when a manual row already covers that subject on that day, so a
     * class the student changed does not come back as a duplicate.
     *
     * @param  list<array{subject: string, section: ?string, day: string, starts_at: string, ends_at: string, room_text: string}>  $meetings
     * @return array{imported: int, kept: int} Rows saved, and COR meetings skipped for a manual row.
     */
    public function replaceCorRows(User $user, AcademicTerm $term, array $meetings): array
    {
        return DB::transaction(function () use ($user, $term, $meetings) {
            $rows = $user->classSchedules()->where('academic_term_id', $term->id);

            (clone $rows)->where('source', ScheduleSource::Cor)->delete();

            $key = fn (string $subject, string $day): string => RoomMatcher::normalize($subject).'|'.$day;
            $manual = $rows->get()->mapWithKeys(fn (ClassSchedule $class) => [$key($class->subject, $class->day->value) => true]);

            $new = array_values(array_filter($meetings, fn (array $meeting) => ! $manual->has($key($meeting['subject'], $meeting['day']))));

            $user->classSchedules()->createMany(array_map(fn (array $meeting) => [
                ...$meeting,
                'academic_term_id' => $term->id,
                'source' => ScheduleSource::Cor,
            ], $new));

            return ['imported' => count($new), 'kept' => count($meetings) - count($new)];
        });
    }
}
