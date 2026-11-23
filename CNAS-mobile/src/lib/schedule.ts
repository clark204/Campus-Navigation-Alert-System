import type { ClassItem, User, Weekday } from './types';

// Class schedule helpers, like CNAS-web App\Services\ClassSchedules (docs/schedule.md). Class times are campus time
// (Asia/Manila, UTC+8 all year, no daylight saving), whatever time zone the phone is set to.

export const WEEKDAYS: { value: Weekday; label: string; short: string }[] = [
  { value: 'mon', label: 'Monday', short: 'Mon' },
  { value: 'tue', label: 'Tuesday', short: 'Tue' },
  { value: 'wed', label: 'Wednesday', short: 'Wed' },
  { value: 'thu', label: 'Thursday', short: 'Thu' },
  { value: 'fri', label: 'Friday', short: 'Fri' },
  { value: 'sat', label: 'Saturday', short: 'Sat' },
  { value: 'sun', label: 'Sunday', short: 'Sun' },
];

const BY_JS_DAY: Weekday[] = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const CAMPUS_OFFSET_MS = 8 * 60 * 60 * 1000;

export const dayLabel = (day: Weekday) => WEEKDAYS.find((d) => d.value === day)?.label ?? day;

/** Today's weekday and the time ("14:05") on campus. */
export function campusNow(now = new Date()): { day: Weekday; time: string } {
  const campus = new Date(now.getTime() + CAMPUS_OFFSET_MS);
  const pad = (n: number) => String(n).padStart(2, '0');
  return { day: BY_JS_DAY[campus.getUTCDay()], time: `${pad(campus.getUTCHours())}:${pad(campus.getUTCMinutes())}` };
}

/** "16:00" → "4:00 PM". */
export function formatTime(time: string): string {
  const [h, m] = time.split(':').map(Number);
  const hour = h % 12 === 0 ? 12 : h % 12;
  return `${hour}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

const byTime = (a: ClassItem, b: ClassItem) => a.starts_at.localeCompare(b.starts_at) || a.subject.localeCompare(b.subject);

export const classesOn = (classes: ClassItem[], day: Weekday) => classes.filter((c) => c.day === day).sort(byTime);

/** Monday to Saturday always; Sunday only when it has a class (like the web). */
export function weekDays(classes: ClassItem[]): Weekday[] {
  return WEEKDAYS.map((d) => d.value).filter((day) => day !== 'sun' || classes.some((c) => c.day === 'sun'));
}

export type When = 'Now' | 'Today' | 'Tomorrow' | string;

/**
 * The class to go to next (ClassSchedules::next): the first one today that hasn't ended (a class in progress is
 * "Now"), else the first one on the next day with classes, up to the same day next week.
 */
export function nextClass(classes: ClassItem[], now = new Date()): { class: ClassItem; when: When } | null {
  const { day, time } = campusNow(now);
  const start = BY_JS_DAY.indexOf(day);
  for (let ahead = 0; ahead <= 7; ahead++) {
    const weekday = BY_JS_DAY[(start + ahead) % 7];
    const found = classesOn(classes, weekday).find((c) => ahead > 0 || c.ends_at > time);
    if (found) {
      const when = ahead === 0 ? (found.starts_at <= time ? 'Now' : 'Today') : ahead === 1 ? 'Tomorrow' : dayLabel(weekday);
      return { class: found, when };
    }
  }
  return null;
}

/** Is this class going on right now? */
export function inProgress(item: ClassItem, now = new Date()): boolean {
  const { day, time } = campusNow(now);
  return item.day === day && item.starts_at <= time && item.ends_at > time;
}

export type RoomKind = 'room' | 'unmapped' | 'online' | 'tba';

const normalize = (text: string) => text.toLowerCase().replace(/[^a-z0-9]+/g, '');

/** Where a class meets, in the web's four forms (portal/class-room.blade.php). */
export function roomOf(item: ClassItem): { kind: RoomKind; label: string } {
  if (item.room_code && item.room_name) return { kind: 'room', label: item.room_name };
  const key = normalize(item.room_text);
  if (key === 'online') return { kind: 'online', label: 'Online class' };
  if (key === 'tba' || key === '') return { kind: 'tba', label: 'Room not announced yet' };
  return { kind: 'unmapped', label: `${item.room_text} (not on the map yet)` };
}

/** Students have "My schedule" (with Import from COR), faculty "My classes"; staff don't teach. */
export function scheduleName(user: User | null): string | null {
  if (user?.role === 'student') return 'My schedule';
  if (user?.role === 'faculty') return 'My classes';
  return null;
}
