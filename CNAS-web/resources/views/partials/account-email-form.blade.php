{{-- Settings → My account → Email, for the admin and the portals (shared AccountController). $routes: the route name
     prefix (admin.settings.account, student.settings.account, …).
     A new email is only saved after the code sent to it is entered. While a change is pending ($emailChange from the
     view composer in AppServiceProvider), the form asks for that code instead. Errors use the accountEmail bag.
     A verified email shows no button until a different address is typed (resources/js/email-change.js). An unverified
     one (the admin is exempt from the sign-in check) gets "Verify email", which sends the same code to the current
     address. --}}
@use('App\Services\EmailCodes')
@php
    $label ??= 'mb-1.5 block text-xs font-semibold text-text-primary';
    $fieldError ??= 'mt-1 text-xs text-red-600';
    $primaryButton ??= 'rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60';
    $quietButton = 'rounded-lg border border-border px-3.5 py-2 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:opacity-50';
    $emailErrors = $errors->getBag('accountEmail');
    $codeError = $emailErrors->first('code') ?: null;
    $isVerified = $user->hasVerifiedEmail();
    $verifyingCurrent = $emailChange && $emailChange->email === $user->email;
    $emailValue = $emailErrors->any() ? old('email', $user->email) : $user->email;
    $typedCurrent = mb_strtolower(trim((string) $emailValue)) === mb_strtolower($user->email);
@endphp

@if ($emailChange)
    <div class="mt-4 grid gap-4 border-t border-border px-5 py-5">
        <h3 class="text-sm font-semibold">Email</h3>

        <div class="flex items-start gap-3 rounded-xl border border-secondary/40 bg-secondary/10 px-4 py-3">
            <svg class="mt-0.5 size-5 shrink-0 text-secondary-hover" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="14" rx="2" />
                <path d="m3 7 9 6 9-6" />
            </svg>
            <p class="text-sm text-text-primary">
                @if ($emailChangeUsable)
                    Enter the {{ EmailCodes::LENGTH }}-digit code we sent to <strong class="font-semibold break-all">{{ $emailChange->email }}</strong>.
                @else
                    The code for <strong class="font-semibold break-all">{{ $emailChange->email }}</strong> can’t be used any more. Send a new one.
                @endif
                @unless ($verifyingCurrent)
                    <span class="block text-text-muted">Until then you still sign in with {{ $user->email }}.</span>
                @endunless
            </p>
        </div>

        <form method="POST" action="{{ route("{$routes}.email.confirm") }}" novalidate data-submit-once class="grid gap-3 sm:grid-cols-[minmax(0,14rem)_auto] sm:items-end">
            @csrf
            @method('PUT')
            <div>
                <label for="account-email-code" class="{{ $label }}">Code from the email</label>
                <input id="account-email-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                    pattern="[0-9]*" maxlength="{{ EmailCodes::LENGTH + 2 }}" required placeholder="123456"
                    class="login-input font-semibold tracking-[.3em] tabular-nums"
                    @if ($codeError) aria-invalid="true" aria-describedby="account-email-code-error" @endif>
            </div>
            <div>
                <button type="submit" class="{{ $primaryButton }}">{{ $verifyingCurrent ? 'Verify email' : 'Confirm new email' }}</button>
            </div>
            @if ($codeError)
                <p id="account-email-code-error" class="{{ $fieldError }} sm:col-span-2">{{ $codeError }}</p>
            @endif
            @error('email', 'accountEmail') <p class="{{ $fieldError }} sm:col-span-2">{{ $message }}</p> @enderror
        </form>

        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route("{$routes}.email.resend") }}" data-submit-once>
                @csrf
                @include('partials.resend-code-button', ['class' => $quietButton, 'seconds' => $emailChangeResendIn])
            </form>
            <form method="POST" action="{{ route("{$routes}.email.cancel") }}" data-submit-once>
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg px-3.5 py-2 text-sm font-semibold text-text-muted transition hover:text-primary">{{ $verifyingCurrent ? 'Cancel' : 'Cancel change' }}</button>
            </form>
        </div>
    </div>
@else
    <form method="POST" action="{{ route("{$routes}.email") }}" novalidate data-submit-once data-email-change
        data-current-email="{{ $user->email }}" data-verified="{{ $isVerified ? 'true' : 'false' }}" class="mt-4 grid gap-4 border-t border-border px-5 py-5">
        @csrf
        @method('PUT')
        <h3 class="flex items-center gap-2 text-sm font-semibold">
            Email
            @if ($isVerified)
                <span class="inline-flex items-center gap-1 rounded-full bg-safe-tint px-2 py-0.5 text-xs font-semibold text-safe">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m5 12.5 4.5 4.5L19 7.5" />
                    </svg>
                    Verified
                </span>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-secondary/15 px-2 py-0.5 text-xs font-semibold text-primary">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7.5v5M12 16.25v.25" />
                    </svg>
                    Not verified
                </span>
            @endif
        </h3>
        <div>
            <label for="account-email" class="{{ $label }}">Sign-in email</label>
            <input id="account-email" name="email" type="email" required maxlength="255" autocomplete="email" autocapitalize="none" spellcheck="false"
                value="{{ $emailValue }}" class="login-input"
                aria-describedby="account-email-hint @error('email', 'accountEmail') account-email-error @enderror"
                @error('email', 'accountEmail') aria-invalid="true" @enderror>
            <p id="account-email-hint" class="mt-1 text-xs text-text-muted">
                @if ($isVerified)
                    To change it, type the new address and we’ll send it a code. Your email only changes after you enter it.
                @else
                    Verify this address with a code we email to it, or type a different address to send the code there.
                @endif
            </p>
            @error('email', 'accountEmail') <p id="account-email-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
            @if ($codeError) <p class="{{ $fieldError }}">{{ $codeError }}</p> @endif
        </div>
        <div data-email-change-submit{{ $isVerified && $typedCurrent ? ' hidden' : '' }}>
            <button type="submit" class="{{ $primaryButton }}" data-email-change-label>{{ ! $isVerified && $typedCurrent ? 'Verify email' : 'Send code' }}</button>
        </div>
    </form>
@endif
