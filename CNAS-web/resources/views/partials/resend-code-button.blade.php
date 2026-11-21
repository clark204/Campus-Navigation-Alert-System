{{-- "Resend code" submit button with the resend cooldown. $seconds: seconds until a new code can be sent (0 = now).
     resources/js/resend-cooldown.js disables it and counts down; without JavaScript it stays enabled and the server
     answers "Please wait N seconds" (EmailCodes::send). $class: the button's classes. --}}
<button type="submit" class="{{ $class }}" data-resend-cooldown="{{ max(0, (int) $seconds) }}">
    <span data-resend-label>Resend code</span>
</button>
