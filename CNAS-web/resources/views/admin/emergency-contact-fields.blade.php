{{-- The fields of an emergency contact form (Settings): adding one ($saved null) or editing $saved. $bag is the form's
     own error bag (SaveEmergencyContactRequest::errorBagFor) and $fieldId prefixes the input ids. Previous input is only
     used after this form failed, since every contact's form has the same field names. --}}
@php
    $failed = $errors->getBag($bag)->any();
    $old = fn (string $field, mixed $saved) => $failed ? old($field) : $saved;
    $isVisible = $failed ? old('is_visible') === '1' : ($saved?->is_visible ?? true);
    $fieldError = 'mt-1 text-xs text-red-600';
@endphp

<div>
    <label for="{{ $fieldId }}-name" class="mb-1.5 block text-xs font-semibold text-text-primary">Name</label>
    <input id="{{ $fieldId }}-name" name="name" type="text" required maxlength="100" value="{{ $old('name', $saved?->name) }}"
        placeholder="e.g. Campus security" class="login-input"
        @error('name', $bag) aria-invalid="true" aria-describedby="{{ $fieldId }}-name-error" @enderror>
    @error('name', $bag) <p id="{{ $fieldId }}-name-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
</div>

<div>
    <label for="{{ $fieldId }}-phone" class="mb-1.5 block text-xs font-semibold text-text-primary">Phone</label>
    <input id="{{ $fieldId }}-phone" name="phone" type="tel" maxlength="30" inputmode="tel" autocomplete="off" value="{{ $old('phone', $saved?->phone) }}"
        placeholder="e.g. +63 912-345-6789" class="login-input"
        @error('phone', $bag) aria-invalid="true" aria-describedby="{{ $fieldId }}-phone-error" @enderror>
    @error('phone', $bag) <p id="{{ $fieldId }}-phone-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
</div>

<div class="sm:col-span-2">
    <label for="{{ $fieldId }}-note" class="mb-1.5 block text-xs font-semibold text-text-primary">Note <span class="font-normal text-text-muted">(optional)</span></label>
    <input id="{{ $fieldId }}-note" name="note" type="text" maxlength="60" value="{{ $old('note', $saved?->note) }}"
        placeholder="e.g. 24 hours" class="login-input"
        @error('note', $bag) aria-invalid="true" aria-describedby="{{ $fieldId }}-note-error" @enderror>
    @error('note', $bag) <p id="{{ $fieldId }}-note-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
</div>

<div class="sm:col-span-2">
    <input type="hidden" name="is_visible" value="0">
    <label class="flex items-center gap-2.5 text-sm">
        <input type="checkbox" name="is_visible" value="1" @checked($isVisible) class="size-4 rounded border-border accent-primary">
        Show on the portal home
    </label>
</div>
