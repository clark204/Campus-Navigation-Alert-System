{{-- "End alert": how it ended (All clear or False alarm, each swapping in its default message, app.js
     data-resolve-form) and the message everyone gets. Shared by Admin > Emergency alerts and the trusted users' portal
     page. Needs $alert (the active Alert) and $action (the resolve URL). --}}
@use('App\Enums\AlertResolution')
@use('App\Models\Alert')
<form method="POST" action="{{ $action }}" novalidate data-submit-once data-resolve-form
    data-confirm="End this alert? Everyone gets a notification." class="grid content-start gap-3">
    @csrf
    <fieldset>
        <legend class="text-xs font-semibold">How did it end?</legend>
        <div class="mt-1.5 grid grid-cols-2 gap-2">
            @foreach (AlertResolution::cases() as $resolution)
                <label>
                    <input type="radio" name="resolution" value="{{ $resolution->value }}" class="peer sr-only"
                        data-default-message="{{ $resolution->defaultMessage($alert->is_drill) }}"
                        @checked(old('resolution', AlertResolution::AllClear->value) === $resolution->value)>
                    <span class="block cursor-pointer rounded-xl border border-border px-3 py-2.5 text-center text-sm font-semibold transition peer-checked:border-safe peer-checked:bg-safe peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-secondary">
                        {{ $resolution->label() }}
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>
    <label for="resolved_message" class="text-xs font-semibold">Message to everyone</label>
    <textarea id="resolved_message" name="resolved_message" rows="3" maxlength="500" required class="login-input"
        @error('resolved_message') aria-invalid="true" aria-describedby="resolved_message-error" @enderror>{{ old('resolved_message', AlertResolution::AllClear->defaultMessage($alert->is_drill)) }}</textarea>
    @error('resolved_message') <p id="resolved_message-error" class="text-xs text-red-600">{{ $message }}</p> @enderror
    <button type="submit" class="rounded-lg bg-safe px-4 py-2.5 text-sm font-semibold text-white transition hover:brightness-110">
        End alert and notify everyone
    </button>
    <p class="text-xs text-text-muted">The green banner stays up for {{ Alert::ALL_CLEAR_MINUTES }} minutes.</p>
</form>
