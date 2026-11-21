{{-- The alert level picker: three cards (the label, when to choose the level, and an example), then one muted line with
     what students get for the level selected. The line updates as the selection changes (severity-picker.js).
     Inputs: $severities (AlertSeverity cases), $selected (AlertSeverity or null), optional $hint under the question. --}}
<fieldset class="min-w-0" data-severity-picker>
    <legend class="text-sm font-semibold">How serious is it?</legend>
    @if (! empty($hint ?? null))
        <p class="mt-1 text-xs text-text-muted">{{ $hint }}</p>
    @endif

    <div class="mt-2 grid gap-2 sm:grid-cols-3">
        @foreach ($severities as $level)
            <label class="relative min-w-0">
                <input type="radio" name="severity" value="{{ $level->value }}" data-students-get="{{ $level->studentsGet() }}"
                    @checked($selected === $level) class="peer sr-only">
                <span class="flex h-full cursor-pointer flex-col gap-1.5 rounded-xl border border-border bg-surface px-4 py-3 transition peer-checked:border-primary peer-checked:ring-2 peer-checked:ring-primary/20 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-secondary hover:border-primary/50">
                    <span class="flex items-center gap-2 text-sm font-semibold">
                        <span class="size-3 shrink-0 rounded-full" style="background: {{ $level->color() }}" aria-hidden="true"></span>
                        {{ $level->label() }}
                    </span>
                    <span class="text-sm leading-snug text-text-primary">{{ $level->description() }}</span>
                    <span class="text-xs leading-snug text-text-muted">e.g. {{ $level->example() }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <p data-students-get class="mt-2 text-xs leading-relaxed text-text-muted" aria-live="polite">{{ $selected?->studentsGet() }}</p>
</fieldset>
