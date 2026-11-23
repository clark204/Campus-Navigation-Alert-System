{{-- The map-first "Send emergency alert" form, shared by Admin > Emergency alerts and the trusted users' portal page:
     pick the type, tap the map to drop the incident pin (the building under it is selected; more can be added), pick
     the danger zone, check the pre-filled wording, send. One screen on a phone too: the wording folds away and "Send
     alert" stays at the bottom. resources/js/alert-picker.js drives the map; without JavaScript the type, buildings
     and wording still work (a whole-campus alert).
     Needs $action (POST URL), and from the controller $types, $type, $severities, $severity,
     $buildings, $radii, $defaultRadius. --}}
@use('App\Enums\AlertType')

@php
    $templates = collect($types)->mapWithKeys(fn (AlertType $option) => [$option->value => $option->template()]);
    $wording = fn (string $field) => old($field, $type->template()[$field]);
    $checkedBuildings = old('building_codes', []);
    $radius = (int) old('danger_radius_m', $defaultRadius);
    $wordingHasErrors = $errors->hasAny(['title', 'message', 'what_to_do', 'instructions']);
@endphp

<form method="POST" action="{{ $action }}" novalidate data-submit-once data-alert-picker
    data-map-url="{{ route('api.map') }}"
    data-templates="{{ $templates->toJson() }}"
    data-campus-wide="{{ collect($types)->filter->isCampusWide()->map->value->values()->toJson() }}"
    data-confirm="Send this alert to everyone now?"
    class="flex flex-col gap-5 pb-24 lg:pb-0">
    @csrf
    <input type="hidden" name="latitude" value="{{ old('latitude') }}" data-picker-latitude>
    <input type="hidden" name="longitude" value="{{ old('longitude') }}" data-picker-longitude>

    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-alarm sm:text-3xl">Send emergency alert</h1>
        <p class="mt-1 text-sm text-text-muted">Everyone sees it within 15 seconds, and subscribed devices get a notification.</p>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">The alert wasn't sent.</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(20rem,1fr)] lg:items-start">
        {{-- What and where --}}
        <div class="flex min-w-0 flex-col gap-4">
            <fieldset class="min-w-0">
                <legend class="text-sm font-semibold">What is happening?</legend>
                <div class="-mx-4 mt-2 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0">
                    @foreach ($types as $option)
                        <label class="relative shrink-0">
                            <input type="radio" name="type" value="{{ $option->value }}" @checked($type === $option) data-default-severity="{{ $option->defaultSeverity()->value }}" data-type-label="{{ $option->label() }}" class="peer sr-only">
                            <span class="flex h-full cursor-pointer items-center gap-2 rounded-xl border border-border bg-surface px-3.5 py-2.5 text-sm font-semibold whitespace-nowrap transition peer-checked:border-alarm peer-checked:bg-alarm peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-secondary hover:border-alarm/60">
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="{{ $option->icon() }}" />
                                </svg>
                                {{ $option->label() }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            {{-- How serious it is: the type's level is preselected (see alert-picker.js); the admin can change it. --}}
            @include('admin.severity-cards', ['selected' => $severity])

            <section aria-labelledby="where-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
                <h2 id="where-heading" class="sr-only">Where is it?</h2>
                <div class="group/map relative bg-[#d5e8c3]">
                    <div data-picker-map class="h-[42svh] min-h-64 w-full lg:h-[min(48svh,28rem)]" role="application"
                        aria-label="Campus map: tap where the emergency is to drop the incident pin"></div>
                    <p data-picker-hint
                        class="pointer-events-none absolute top-3 left-3 max-w-[calc(100%-1.5rem)] rounded-full bg-white/95 px-3 py-1.5 text-sm font-semibold text-text-primary shadow-md">
                        Tap the map where it's happening
                    </p>
                    <button type="button" data-picker-clear hidden
                        class="absolute top-3 right-3 rounded-full bg-white/95 px-3 py-1.5 text-sm font-semibold text-alarm shadow-md transition hover:bg-white">
                        Remove pin
                    </button>
                    <p class="absolute inset-0 hidden items-center justify-center p-6 text-center text-sm text-text-muted group-data-failed/map:flex">
                        The map couldn't load. Choose the affected buildings below, or send a whole-campus alert.
                    </p>
                </div>

                <div class="flex flex-col gap-4 border-t border-border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="flex min-w-0 items-center gap-2 text-sm">
                            <svg class="size-5 shrink-0 text-alarm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                            </svg>
                            <span class="font-semibold" data-picker-location aria-live="polite">Whole campus</span>
                        </p>

                        <fieldset data-picker-radius class="flex items-center gap-2 disabled:opacity-40" @if (! old('latitude')) disabled @endif>
                            <legend class="sr-only">Danger zone</legend>
                            <span class="text-sm text-text-muted" aria-hidden="true">Danger zone</span>
                            <div class="flex rounded-xl border border-border bg-bg-light p-0.5">
                                @foreach ($radii as $option)
                                    <label>
                                        <input type="radio" name="danger_radius_m" value="{{ $option }}" @checked($radius === $option) class="peer sr-only">
                                        <span class="block cursor-pointer rounded-lg px-3 py-1.5 text-sm font-semibold tabular-nums text-text-muted transition peer-checked:bg-alarm peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-secondary">
                                            {{ $option }} m
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </div>

                    <details data-picker-buildings @if ($checkedBuildings || $errors->has('building_codes.*')) open @endif class="group/buildings">
                        <summary class="cursor-pointer text-sm font-semibold text-primary">
                            Affected buildings <span data-picker-building-count class="font-normal text-text-muted">{{ count($checkedBuildings) ? '('.count($checkedBuildings).')' : '' }}</span>
                        </summary>
                        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-3">
                            @foreach ($buildings as $code => $name)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="building_codes[]" value="{{ $code }}" @checked(in_array($code, $checkedBuildings, true))
                                        data-building-name="{{ $name }}" class="size-4 accent-[#B3121B]">
                                    {{ $name }}
                                </label>
                            @endforeach
                        </div>
                    </details>
                </div>
            </section>
        </div>

        {{-- Wording, drill, send --}}
        <div class="flex flex-col gap-4 lg:sticky lg:top-6">
            <details data-wording @if ($wordingHasErrors) open @endif class="group/wording rounded-2xl border border-border bg-surface">
                <summary class="flex cursor-pointer list-none items-start justify-between gap-3 p-4 [&::-webkit-details-marker]:hidden">
                    <span class="min-w-0">
                        <span class="block text-xs font-medium text-text-muted">Wording everyone sees</span>
                        <span class="mt-0.5 block truncate font-semibold" data-wording-preview>{{ $wording('title') }}</span>
                    </span>
                    <span class="shrink-0 text-sm font-semibold text-primary group-open/wording:hidden">Edit</span>
                </summary>

                <div class="border-t border-border p-4">
                    @include('admin.alert-wording-fields', [
                        'value' => $wording,
                        'preview' => ['type' => $type, 'severity' => $severity ?? $type->defaultSeverity(), 'drill' => (bool) old('is_drill'), 'location' => 'Whole campus'],
                    ])
                </div>
            </details>

            <label class="flex items-start gap-3 rounded-2xl border border-border bg-surface p-4 text-sm has-checked:border-hazard has-checked:bg-hazard/10">
                <input type="checkbox" name="is_drill" value="1" @checked(old('is_drill')) data-picker-drill class="mt-0.5 size-4 accent-[#1c1917]">
                <span><strong>This is a drill.</strong> Everyone sees a large “DRILL” label and hears a softer sound.</span>
            </label>

            {{-- Pinned to the bottom of the screen on phones. --}}
            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface/95 px-4 pt-3 pb-[max(.75rem,env(safe-area-inset-bottom))] backdrop-blur lg:static lg:border-0 lg:bg-transparent lg:p-0">
                <button type="submit"
                    class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-alarm px-5 text-base font-bold text-white shadow-md transition hover:bg-alarm-deep">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 3 2 20h20zM12 10v4m0 3h.01" />
                    </svg>
                    Send alert
                </button>
            </div>
        </div>
    </div>
</form>
