# Class schedule (students and faculty)

Read this before touching "My schedule", faculty "My classes", the Home "Next class" card, room matching, or GET /api/schedule.

## Rules (decided, do not change without asking Clark)
- Table `class_schedules`: one row per class meeting (user, academic term, subject, section, day, starts_at, ends_at, room_text, source).
- Only the current academic term's rows are shown; old terms stay hidden and cannot be edited.
- Students: "My schedule" (`student.schedule.*`), from their COR or added by hand.
- Faculty: "My classes" (`/faculty/classes`, `faculty.classes.*`), added by hand only: there is no COR for teachers and no copy of their schedules (decided 2026-11-18). Same controller and views as students (`ClassScheduleController::portal()` picks the routes, the name and whether the COR import shows). The faculty Home shows their next class and today's classes like a student's.
- Staff share the faculty portal but don't teach: no My classes, no class card on Home (`FacultyDashboardController`, sidebar item only for the Faculty role). A staff member who teaches is switched to Faculty by the admin (Admin > Accounts > "Faculty or staff"; see accounts.md).
- Everyone sees and changes only their own rows (`ClassSchedulePolicy`).
- The add/edit form's room is a dropdown (decided 2026-11-18; teachers may not know room names): "Not announced yet" (default, saved as TBA), "Online class", the map's rooms by building except comfort rooms (`Room::classRooms`), and "Somewhere not on the map…" which shows a box to type the place (Gym, Field). A picked room is saved as its code (`r6`), so the class stays linked when the admin renames the room. Editing a COR row shows its matched room selected; saving it unchanged keeps the COR text and the `cor` source. See `SaveClassScheduleRequest` (`room_choice`, `room_other`, `classAttributes()`).
- No room id is stored. `room_text` is kept exactly as written and matched to a Room each time the schedule is read (`RoomMatcher`), so rooms added to the map later start working without a new import.
- Matching: lowercase, spaces and punctuation removed, "complab" treated as "comlab"; compared with room name and code. "Online" and "TBA" are never rooms. No match shows the text with "(not on the map yet)".
- `source` is `cor` or `manual`. An import replaces the `cor` rows of the current term and leaves `manual` rows alone. A `cor` row the student really changes becomes `manual`. An import skips a COR meeting when a manual row has the same subject on the same day, so an edited class does not come back as a duplicate.
- Import uses the same COR checks as registration (`RegistrationService::acceptCor`), and the COR's student ID must be the signed-in student's. The PDF is deleted right after it is read.
- Registration: the draft carries the COR's meetings and term id. They are saved only when `save_schedule` is true (web checkbox "Also save my class schedule", ticked by default; the API must send `save_schedule: true`, left out means no).
- Class times are campus time (`ClassSchedules::TIMEZONE`, Asia/Manila), stored as 24-hour times.
- Next class: the first class today that has not ended (a class in progress shows "Now"), otherwise the first class on the next day with classes, up to the same day next week.

## Where the code is
- `app/Services/ClassSchedules.php`: forUser (current term, sorted, rooms attached), today, next, importFromCor, replaceCorRows
- `app/Services/RoomMatcher.php`; `app/Enums/Weekday.php` (also parses COR days: "Mon/Wed", "MW", "TTH"); `app/Enums/ScheduleSource.php`
- COR meetings: `CorExtractor::subjects()/meetings()`, `ParsedCor::meetings()`
- Web: `Portal/ClassScheduleController`, `Portal/ClassScheduleImportController`, `Portal/StudentDashboardController`; routes `student.schedule.*` under /student/schedule
- Views: `resources/views/portal/schedule-*.blade.php`, `class-row.blade.php`, `class-room.blade.php`; the Home card is in `dashboard-page.blade.php`
- API: GET /api/schedule (Sanctum), `Api/ScheduleController`, `ClassScheduleResource` (room_code and room_name are null when there is no match)

## Gotchas
- MySQL returns TIME as "16:00:00"; the model's accessors cut it to "16:00". Do not use isDirty() to detect an edit.
- In the pdftotext COR layout the subject row sits between its meeting lines; in the smalot layout the lines follow the row. Both are handled in `CorExtractor::subjects()`.

## Tests
- `tests/Feature/Schedule/`, `tests/Unit/Cor/CorMeetingsTest.php`
