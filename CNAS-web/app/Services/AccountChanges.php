<?php

namespace App\Services;

use App\Enums\EmailCodePurpose;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Settings → My account, shared by the web (AccountController) and the mobile app (Api\AccountController): the
 * signed-in user changes their own email or password. Each method answers with the message to show.
 *
 * A new email only replaces the old one after the code sent to it is entered (EmailCodes, purpose change_email), so a
 * typo can't lock anyone out. Until then the user keeps signing in with the old email. EmailCodes errors are on
 * "code"; the others are on "email".
 */
class AccountChanges
{
    public function __construct(private EmailCodes $codes) {}

    /**
     * Send a code to the new address. The same address again: nothing to change when it is verified; otherwise "Verify
     * email" (e.g. the admin's), the same change_email code to the address the user already has.
     *
     * @throws ValidationException (key "code") during the wait between codes.
     */
    public function requestEmailChange(User $user, string $email): string
    {
        if ($email === $user->email && $user->hasVerifiedEmail()) {
            $this->codes->forget($user, EmailCodePurpose::ChangeEmail);

            return "{$email} is already your sign-in email.";
        }

        $this->codes->send($user, EmailCodePurpose::ChangeEmail, $email);

        return $email === $user->email
            ? "We sent a code to {$email}. Enter it below to verify your email."
            : "We sent a code to {$email}. Enter it below to finish changing your email.";
    }

    /**
     * Check the code, then save the address it went to (verified, since the code proves it).
     *
     * @throws ValidationException (key "code" when the code is wrong, "email" when another account took the address)
     */
    public function confirmEmailChange(User $user, string $code): string
    {
        $email = $this->codes->verify($user, EmailCodePurpose::ChangeEmail, $code);

        try {
            $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Someone else started using this email after the code was sent.
            throw ValidationException::withMessages(['email' => 'Another account already uses this email.']);
        }

        return $user->wasChanged('email') ? "Email changed. Sign in with {$email} from now on." : 'Email verified.';
    }

    /**
     * @throws ValidationException (key "email" when no change is waiting, "code" during the wait between codes)
     */
    public function resendEmailChangeCode(User $user): string
    {
        $pending = $this->pendingEmailChange($user);

        if ($pending === null) {
            throw ValidationException::withMessages(['email' => 'Enter your new email again to get a code.']);
        }

        $this->codes->send($user, EmailCodePurpose::ChangeEmail, $pending->email);

        return "We sent a new code to {$pending->email}.";
    }

    public function cancelEmailChange(User $user): string
    {
        $pending = $this->pendingEmailChange($user);
        $this->codes->forget($user, EmailCodePurpose::ChangeEmail);

        return $pending?->email === $user->email
            ? 'Okay, your email stays unverified for now.'
            : "Email change cancelled. You still sign in with {$user->email}.";
    }

    /**
     * The change waiting for its code (its `email` is the new address), or null.
     */
    public function pendingEmailChange(User $user): ?EmailCode
    {
        return $this->codes->current($user, EmailCodePurpose::ChangeEmail);
    }

    /**
     * Seconds until another code can be sent for the waiting change (0 when it can be sent now).
     */
    public function secondsUntilResend(User $user): int
    {
        return $this->codes->secondsUntilResend($user, EmailCodePurpose::ChangeEmail);
    }

    /**
     * Save the new password and a new remember token, so a "Remember me" cookie on another browser can't sign it back
     * in. The caller then ends the other sessions (UserSessions), keeping the browser or phone that made the change.
     */
    public function changePassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->forceFill(['must_change_password' => false]);
        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
