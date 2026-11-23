{{-- The alert's wording, shared by the send form (partials/alert-send-form) and Edit alert: a phone preview of what
     people see, then the title (max 60, live counter), "What to do now" (one sentence, max 100, required for an
     emergency: the bold line on the takeover, toasts and push), and the details people find under "More details"
     (what is happening, longer instructions). resources/js/alert-wording.js runs the counters and the preview.
     Inputs: $value (fn(field) => current value), and for the preview $preview: type (AlertType), severity
     (AlertSeverity), drill (bool), location (string); the send form's picker keeps them up to date. --}}
@use('App\Enums\AlertType')
@use('App\Models\Alert')

<div class="grid gap-4" data-alert-wording>
    @include('admin.alert-phone-preview', $preview)

    <div>
        <div class="mb-1.5 flex items-baseline justify-between gap-3">
            <label for="title" class="text-xs font-semibold">Title</label>
            <span class="text-xs text-text-muted tabular-nums" data-count-for="title" aria-hidden="true"></span>
        </div>
        <input id="title" name="title" type="text" maxlength="{{ Alert::TITLE_MAX }}" required value="{{ $value('title') }}" class="login-input"
            aria-describedby="title-hint @error('title') title-error @enderror" @error('title') aria-invalid="true" @enderror>
        <p id="title-hint" class="sr-only">Up to {{ Alert::TITLE_MAX }} characters.</p>
        @error('title') <p id="title-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <div class="mb-1.5 flex items-baseline justify-between gap-3">
            <label for="what_to_do" class="text-xs font-semibold">What to do now <span class="font-normal text-text-muted" data-what-to-do-optional>(optional for warnings and advisories)</span></label>
            <span class="text-xs text-text-muted tabular-nums" data-count-for="what_to_do" aria-hidden="true"></span>
        </div>
        <input id="what_to_do" name="what_to_do" type="text" maxlength="{{ Alert::WHAT_TO_DO_MAX }}" value="{{ $value('what_to_do') }}" class="login-input"
            placeholder="Leave the building now. Go to the open field."
            aria-describedby="what-to-do-hint @error('what_to_do') what-to-do-error @enderror" @error('what_to_do') aria-invalid="true" @enderror>
        <p id="what-to-do-hint" class="mt-1 text-xs text-text-muted">One short sentence, in bold on everyone's screen. Up to {{ Alert::WHAT_TO_DO_MAX }} characters.</p>
        @error('what_to_do') <p id="what-to-do-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <fieldset class="grid gap-4 rounded-xl border border-dashed border-border p-4">
        <legend class="px-1 text-xs font-semibold text-text-muted">Under "More details"</legend>
        <div>
            <label for="message" class="mb-1.5 block text-xs font-semibold">What is happening</label>
            <textarea id="message" name="message" rows="2" maxlength="1000" required class="login-input"
                @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ $value('message') }}</textarea>
            @error('message') <p id="message-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="instructions" class="mb-1.5 block text-xs font-semibold">More instructions <span class="font-normal text-text-muted">(optional)</span></label>
            <textarea id="instructions" name="instructions" rows="3" maxlength="2000" class="login-input"
                @error('instructions') aria-invalid="true" aria-describedby="instructions-error" @enderror>{{ $value('instructions') }}</textarea>
            @error('instructions') <p id="instructions-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </fieldset>
</div>
