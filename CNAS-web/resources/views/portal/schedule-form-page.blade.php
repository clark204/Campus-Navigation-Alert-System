{{-- "My schedule" (students) or "My classes" (faculty): add a class ($class is null) or edit one, with delete below
     the edit form. $scheduleRoute, $scheduleName and $isStudent come from Portal\ClassScheduleController::portal(). --}}
@extends('layout.portal-layout')
@use('App\Enums\ScheduleSource')
@use('App\Enums\Weekday')
@use('App\Http\Requests\Portal\SaveClassScheduleRequest')

{{-- Block form, not the one-line form: this view has another PHP block, and Blade would read from a one-line
     opener all the way to that block's end. --}}
@php
    $title = $class ? 'Edit class' : 'Add a class';
@endphp

@section('title', $title)
@section('heading', $scheduleName)

@section('content.portal')
    <div class="mx-auto flex max-w-xl flex-col gap-6">
        <div>
            <a href="{{ route("{$scheduleRoute}.index") }}" class="text-sm font-semibold text-primary underline-offset-4 hover:underline">{{ $scheduleName }}</a>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-primary sm:text-3xl">{{ $title }}</h1>
            @if ($class?->source === ScheduleSource::Cor)
                <p class="mt-1 text-sm text-text-muted">
                    This class came from your COR. If you change it, your version is kept the next time you import a COR.
                </p>
            @endif
        </div>

        <section aria-label="{{ $title }}" class="rounded-2xl border border-border bg-surface p-5">
            <form method="POST" novalidate data-submit-once class="grid gap-4"
                action="{{ $class ? route("{$scheduleRoute}.update", $class) : route("{$scheduleRoute}.store") }}">
                @csrf
                @if ($class)
                    @method('PUT')
                @endif

                <div class="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div>
                        <label for="subject" class="mb-1.5 block text-xs font-semibold text-text-primary">Subject</label>
                        <input id="subject" name="subject" type="text" required maxlength="50" autocomplete="off" placeholder="IT 119"
                            value="{{ old('subject', $class?->subject) }}" class="login-input text-base"
                            @error('subject') aria-invalid="true" aria-describedby="subject-error" @enderror>
                        @error('subject') <p id="subject-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="section" class="mb-1.5 block text-xs font-semibold text-text-primary">
                            Section <span class="font-normal text-text-muted">(optional)</span>
                        </label>
                        <input id="section" name="section" type="text" maxlength="30" autocomplete="off" placeholder="BSIT3B"
                            value="{{ old('section', $class?->section) }}" class="login-input text-base"
                            @error('section') aria-invalid="true" aria-describedby="section-error" @enderror>
                        @error('section') <p id="section-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="day" class="mb-1.5 block text-xs font-semibold text-text-primary">Day</label>
                    <select id="day" name="day" required class="login-input text-base"
                        @error('day') aria-invalid="true" aria-describedby="day-error" @enderror>
                        <option value="" disabled @selected(old('day', $class?->day->value) === null)>Choose a day</option>
                        @foreach (Weekday::cases() as $day)
                            <option value="{{ $day->value }}" @selected(old('day', $class?->day->value) === $day->value)>{{ $day->label() }}</option>
                        @endforeach
                    </select>
                    @error('day') <p id="day-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    @foreach ([['id' => 'starts_at', 'label' => 'Starts'], ['id' => 'ends_at', 'label' => 'Ends']] as $field)
                        <div>
                            <label for="{{ $field['id'] }}" class="mb-1.5 block text-xs font-semibold text-text-primary">{{ $field['label'] }}</label>
                            <input id="{{ $field['id'] }}" name="{{ $field['id'] }}" type="time" required
                                value="{{ old($field['id'], $class?->{$field['id']}) }}" class="login-input text-base"
                                @error($field['id']) aria-invalid="true" aria-describedby="{{ $field['id'] }}-error" @enderror>
                            @error($field['id']) <p id="{{ $field['id'] }}-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                {{-- Room: picked from the map's rooms. "Somewhere not on the map" shows a box to type the place
                     (resources/js/app.js data-room-picker; without JavaScript the box always shows). --}}
                @php
                    $chosenRoom = old('room_choice', $roomChoice['choice']);
                    $otherRoom = old('room_other', $roomChoice['other']);
                @endphp
                <div data-room-picker class="grid gap-3">
                    <div>
                        <label for="room_choice" class="mb-1.5 block text-xs font-semibold text-text-primary">Room</label>
                        <select id="room_choice" name="room_choice" class="login-input text-base" aria-describedby="room_choice-hint @error('room_choice') room_choice-error @enderror"
                            @error('room_choice') aria-invalid="true" @enderror>
                            <option value="{{ SaveClassScheduleRequest::CHOICE_TBA }}" @selected($chosenRoom === SaveClassScheduleRequest::CHOICE_TBA)>Not announced yet</option>
                            <option value="{{ SaveClassScheduleRequest::CHOICE_ONLINE }}" @selected($chosenRoom === SaveClassScheduleRequest::CHOICE_ONLINE)>Online class</option>
                            @foreach ($roomsByBuilding as $building => $rooms)
                                <optgroup label="{{ $building }}">
                                    @foreach ($rooms as $room)
                                        <option value="{{ $room->code }}" @selected($chosenRoom === $room->code)>{{ $room->choiceLabel() }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                            <option value="{{ SaveClassScheduleRequest::CHOICE_OTHER }}" @selected($chosenRoom === SaveClassScheduleRequest::CHOICE_OTHER)>Somewhere not on the map…</option>
                        </select>
                        <p id="room_choice-hint" class="mt-1 text-xs text-text-muted">
                            Not sure yet? Leave it as “Not announced yet” and change it when you know. A room on the map gets a Go button for directions.
                        </p>
                        @error('room_choice') <p id="room_choice-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div data-room-other>
                        <label for="room_other" class="mb-1.5 block text-xs font-semibold text-text-primary">Where does it meet?</label>
                        <input id="room_other" name="room_other" type="text" maxlength="100" autocomplete="off" placeholder="Gym, Field"
                            value="{{ $otherRoom }}" class="login-input text-base"
                            @error('room_other') aria-invalid="true" aria-describedby="room_other-error" @enderror>
                        @error('room_other') <p id="room_other-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-lg bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-70">
                        {{ $class ? 'Save class' : 'Add class' }}
                    </button>
                    <a href="{{ route("{$scheduleRoute}.index") }}" class="inline-flex min-h-11 items-center px-2 text-sm font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Cancel</a>
                </div>
            </form>
        </section>

        @if ($class)
            <section aria-labelledby="delete-heading" class="rounded-2xl border border-border bg-surface p-5">
                <h2 id="delete-heading" class="font-semibold">Delete this class</h2>
                <p class="mt-0.5 text-sm text-text-muted">
                    Removes {{ $class->subject }} on {{ $class->day->label() }}, {{ $class->timeLabel() }} from your schedule.
                </p>
                <form method="POST" action="{{ route("{$scheduleRoute}.destroy", $class) }}" class="mt-4"
                    data-confirm="Delete {{ $class->subject }} on {{ $class->day->label() }} from your schedule?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-lg border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100">
                        Delete class
                    </button>
                </form>
            </section>
        @endif
    </div>
@endsection
