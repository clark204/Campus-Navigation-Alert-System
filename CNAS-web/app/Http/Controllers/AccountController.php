<?php

namespace App\Http\Controllers;

use App\Enums\EmailCodePurpose;
use App\Http\Requests\Account\ConfirmEmailChangeRequest;
use App\Http\Requests\Account\UpdateEmailRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\User;
use App\Services\EmailCodes;
use App\Services\UserSessions;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Settings → My account, for everyone: the signed-in user changes their own email or password. The admin uses it
 * from /admin/settings, students and faculty from their portal's settings (routes admin.settings.account.*,
 * student.settings.account.*, faculty.settings.account.*). AdminSeeder never overwrites what the admin sets here.
 *
 * A new email only replaces the old one after the code sent to it is entered (EmailCodes, purpose change_email), so a
 * typo can't lock anyone out. Until then the user keeps signing in with the old email.
 */
class AccountController extends Controller
{
    public function __construct(private EmailCodes $codes) {}

    public function updateEmail(UpdateEmailRequest $request): RedirectResponse
    {
        $user = $request->user();
        $email = $request->validated('email');

        if ($email === $user->email && $user->hasVerifiedEmail()) {
            $this->codes->forget($user, EmailCodePurpose::ChangeEmail);

            return $this->back($user, 'status', "{$email} is already your sign-in email.");
        }

        // "Verify email" (an unverified current email, e.g. the admin's): the same change_email code, sent to the
        // address the user already has; confirming it only sets email_verified_at.
        if ($email === $user->email) {
            $this->inEmailForm($user, fn () => $this->codes->send($user, EmailCodePurpose::ChangeEmail, $email));

            return $this->back($user, 'status', "We sent a code to {$email}. Enter it below to verify your email.");
        }

        $this->inEmailForm($user, fn () => $this->codes->send($user, EmailCodePurpose::ChangeEmail, $email));

        return $this->back($user, 'status', "We sent a code to {$email}. Enter it below to finish changing your email.");
    }

    public function confirmEmail(ConfirmEmailChangeRequest $request): RedirectResponse
    {
        $user = $request->user();
        $email = $this->inEmailForm($user, fn () => $this->codes->verify($user, EmailCodePurpose::ChangeEmail, $request->validated('code')));

        try {
            // The code proves the new address, so it is verified too.
            $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Someone else started using this email after the code was sent.
            throw $this->emailFormError($user, ['email' => 'Another account already uses this email.']);
        }

        if (! $user->wasChanged('email')) {
            return $this->back($user, 'status', 'Email verified.');
        }

        return $this->back($user, 'status', "Email changed. Sign in with {$email} from now on.");
    }

    public function resendEmailCode(Request $request): RedirectResponse
    {
        $user = $request->user();
        $pending = $this->codes->current($user, EmailCodePurpose::ChangeEmail);

        if ($pending === null) {
            throw $this->emailFormError($user, ['email' => 'Enter your new email again to get a code.']);
        }

        $this->inEmailForm($user, fn () => $this->codes->send($user, EmailCodePurpose::ChangeEmail, $pending->email));

        return $this->back($user, 'status', "We sent a new code to {$pending->email}.");
    }

    public function cancelEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $pending = $this->codes->current($user, EmailCodePurpose::ChangeEmail);
        $this->codes->forget($user, EmailCodePurpose::ChangeEmail);

        if ($pending?->email === $user->email) {
            return $this->back($user, 'status', 'Okay, your email stays unverified for now.');
        }

        return $this->back($user, 'status', "Email change cancelled. You still sign in with {$user->email}.");
    }

    public function updatePassword(UpdatePasswordRequest $request, UserSessions $sessions): RedirectResponse
    {
        $user = $request->user();
        $password = $request->validated('password');

        // A new remember token: a "Remember me" cookie on another browser can't sign it back in.
        $user->password = $password;
        $user->forceFill(['must_change_password' => false]);
        $user->setRememberToken(Str::random(60));
        $user->save();

        // This browser stays signed in: a new session id, and its own "Remember me" cookie (if any) gets the new token.
        $request->session()->regenerate();
        Auth::guard('web')->logoutOtherDevices($password);
        $sessions->endAll($user, keepSessionId: $request->session()->getId());

        return $this->back($user, 'status', 'Password changed. You were signed out everywhere else, including the mobile app.');
    }

    private function back(User $user, string $key, string $message): RedirectResponse
    {
        return redirect($user->settingsUrl('my-account'))->with('section', 'account')->with($key, $message);
    }

    /**
     * Runs an EmailCodes call; its errors ("code": wrong, expired, wait before resending) show on the email form.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function inEmailForm(User $user, Closure $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            throw $this->emailFormError($user, $exception->errors());
        }
    }

    /**
     * @param  array<string, string|list<string>>  $messages
     */
    private function emailFormError(User $user, array $messages): ValidationException
    {
        return ValidationException::withMessages($messages)
            ->errorBag('accountEmail')
            ->redirectTo($user->settingsUrl('my-account'));
    }
}
