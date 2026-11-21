{{-- Admin "Edit on map": every 360° photo on the campus map. Pick a pin to edit its photo in the side panel (its viewer,
     north, "Show to users"); drag pins to move them; "Save all" saves every change in one request. Desktop only. --}}
@extends('layout.admin-layout')

@section('title', 'Edit 360° photos on the map')
@section('heading', 'Edit 360° photos on the map')

@section('content.admin')
    {{-- Below 1024 px the editor is not built: the list still works on a phone. --}}
    <div class="mx-auto max-w-md rounded-2xl border border-border bg-surface p-6 text-center lg:hidden">
        <p class="font-semibold text-primary">Open this page on a computer to edit photo positions.</p>
        <p class="mt-2 text-sm text-text-muted">The list of 360° photos still works on a phone.</p>
        <a href="{{ route('admin.panoramas.index') }}" class="mt-4 inline-block rounded-lg border border-border px-4 py-2 text-sm font-semibold text-text-primary transition hover:border-primary hover:text-primary">
            Back to the list
        </a>
    </div>

    <div class="hidden flex-col gap-3 lg:flex" style="height: calc(100dvh - 7.5rem);"
        data-panorama-map-editor data-points="{{ $points->toJson() }}" data-selected="{{ $selected }}" data-floor="{{ $floor }}"
        data-map-url="{{ route('api.map') }}" data-save-url="{{ route('admin.panoramas.batch') }}" data-list-url="{{ route('admin.panoramas.index') }}"
        data-max-floors="{{ $maxFloors }}">
        {{-- Toolbar: floors, status, save. --}}
        <div class="flex flex-wrap items-center gap-3">
            <div role="group" aria-label="Floor" class="flex items-center gap-1 rounded-xl border border-border bg-surface p-1">
                @for ($level = 1; $level <= $maxFloors; $level++)
                    <button type="button" data-floor-button="{{ $level }}" aria-pressed="{{ $level === $floor ? 'true' : 'false' }}"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold text-text-muted transition hover:text-primary aria-pressed:bg-primary aria-pressed:text-white">
                        {{ Number::ordinal($level) }} floor
                    </button>
                @endfor
            </div>
            <p data-editor-status role="status" aria-live="polite" class="min-w-0 flex-1 text-sm text-text-muted">Click a pin to select a photo.</p>
            <a href="{{ route('admin.panoramas.index') }}" class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-primary transition hover:border-primary hover:text-primary">
                Back to the list
            </a>
            <button type="button" data-discard disabled
                class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-primary transition hover:border-red-600 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40">
                Discard
            </button>
            <button type="button" data-save-all disabled
                class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-40">
                Save all <span data-changed-count></span>
            </button>
        </div>
        <p data-save-errors role="alert" hidden class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700"></p>

        {{-- The photo panel takes about 40% of the width (380 to 640 px), so the photo is big enough to read. --}}
        <div class="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_clamp(380px,40%,640px)] gap-4">
            <div data-editor-map class="relative min-h-0 overflow-hidden rounded-2xl border border-border bg-[#d5e8c3]">
                <p data-editor-map-failed hidden class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-text-muted">
                    The campus map couldn't load. Check your connection and reload the page.
                </p>
            </div>

            <aside aria-label="Selected photo" class="@container flex min-h-0 flex-col overflow-y-auto rounded-2xl border border-border bg-surface p-4">
                <p data-panel-empty class="text-sm text-text-muted">Click a pin on the map to select its photo. Changed pins are marked in orange.</p>

                <div data-panel hidden class="grid gap-4">
                    <div>
                        <h2 data-panel-title class="break-words text-base font-semibold"></h2>
                        <p data-panel-code class="font-mono text-xs text-text-muted"></p>
                    </div>

                    {{-- 2. North: the viewer, with the walkways drawn on the ground when "Line it up" is on. --}}
                    <div class="grid gap-2">
                        <p class="text-sm font-semibold">North</p>
                        <div class="relative aspect-3/2 overflow-hidden rounded-xl border border-border bg-neutral-900">
                            <div data-pano-viewer class="absolute inset-0"></div>
                            <svg data-overlay aria-hidden="true" class="pointer-events-none absolute inset-0 h-full w-full"></svg>
                            {{-- The red line: the middle of the view, the direction the photo is facing. North is set from what is on it. --}}
                            <div data-centre-line aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-1/2 w-[3px] -translate-x-1/2 bg-red-600 ring-1 ring-white/80"></div>
                            <p data-viewer-failed hidden class="absolute inset-0 flex items-center justify-center p-4 text-center text-xs text-white/80">
                                The photo couldn't be shown here. You can still type north below.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ([-5, -1, 1, 5] as $step)
                                <button type="button" data-nudge="{{ $step }}"
                                    class="rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-primary transition hover:border-primary hover:text-primary"
                                    aria-label="Turn {{ abs($step) }}° {{ $step < 0 ? 'left' : 'right' }}">
                                    {{ $step < 0 ? '←' : '→' }} {{ abs($step) }}°
                                </button>
                            @endforeach
                            {{-- The turn buttons and these share one row when the panel is wide; they wrap when it is not. --}}
                            <button type="button" data-line-up aria-pressed="false"
                                class="ml-auto rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary transition hover:border-primary hover:text-primary aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary">
                                Line it up
                            </button>
                            <button type="button" data-set-north disabled
                                class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-40">
                                This spot is on the line
                            </button>
                            <button type="button" data-looks-right hidden
                                class="rounded-lg bg-secondary px-3 py-1.5 text-xs font-semibold text-primary-hover transition hover:bg-secondary-hover">
                                Looks right
                            </button>
                        </div>
                        <p data-north-status aria-live="polite" class="text-xs text-text-muted"></p>
                    </div>

                    {{-- Name, floor and north share one row when the panel is wide. --}}
                    <div class="grid grid-cols-2 gap-3 @md:grid-cols-4">
                        <div class="col-span-2">
                            <label for="panel-name" class="mb-1 block text-xs font-semibold">Name</label>
                            <input id="panel-name" data-field="name" type="text" maxlength="100" class="login-input">
                        </div>
                        <div>
                            <label for="panel-floor" class="mb-1 block text-xs font-semibold">Floor</label>
                            <select id="panel-floor" data-field="floor" class="login-input">
                                @for ($level = 1; $level <= $maxFloors; $level++)
                                    <option value="{{ $level }}">{{ Number::ordinal($level) }} floor</option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label for="panel-north" class="mb-1 block text-xs font-semibold">North <span class="font-normal text-text-muted">(degrees)</span></label>
                            <input id="panel-north" data-field="north" type="number" inputmode="decimal" min="0" max="360" step="0.01" placeholder="Not set" class="login-input font-mono text-xs">
                        </div>
                        <div class="col-span-full flex items-center justify-between gap-2">
                            <p data-panel-coords class="font-mono text-[11px] text-text-muted"></p>
                            <button type="button" data-clear-north class="text-xs font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Clear north</button>
                        </div>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" data-field="visible" class="mt-0.5 size-4 accent-primary">
                        <span>Show to users <span data-visible-why class="block text-xs text-text-muted"></span></span>
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button type="button" data-save-one disabled
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-40">
                            Save this photo
                        </button>
                        <button type="button" data-discard-one disabled
                            class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-primary transition hover:border-red-600 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40">
                            Discard this photo
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
