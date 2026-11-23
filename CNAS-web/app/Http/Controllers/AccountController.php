<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\ConfirmEmailChangeRequest;
use App\Http\Requests\Account\UpdateEmailRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\User;
use App\Services\AccountChanges;
use App\Services\UserSessions;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Settings → My account, for everyone: the signed-in user changes their own email or password. The admin uses it
 * from /admin/settings, students and faculty from their portal's settings (routes admin.settings.account.*,
 * student.settings.account.*, faculty.settings.account.*). AdminSeeder never overwrites what the admin sets here.
 * The rules live in AccountChanges, shared with the mobile app (Api\AccountController).
 */
class AccountController extends Controller
{
    public function __construct(private AccountChanges $changes) {}

    public function updateEmail(UpdateEmailRequest $request): RedirectResponse
    {
        $user = $request->user();

        return $this->back($user, $this->inEmailForm($user, fn () => $this->changes->requestEmailChange($user, $request->validated('email'))));
    }

    public function confirmEmail(ConfirmEmailChangeRequest $request): RedirectResponse
    {
        $user = $request->user();

        return $this->back($user, $this->inEmailForm($user, fn () => $this->changes->confirmEmailChange($user, $request->validated('code'))));
    }

    public function resendEmailCode(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $this->back($user, $this->inEmailForm($user, fn () => $this->changes->resendEmailChangeCode($user)));
    }

    public function cancelEmail(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $this->back($user, $this->changes->cancelEmailChange($user));
    }

    public function updatePassword(UpdatePasswordRequest $request, UserSessions $sessions): RedirectResponse
    {
        $user = $request->user();
        $password = $request->validated('password');

        $this->changes->changePassword($user, $password);

        // This browser stays signed in: a new session id, and its own "Remember me" cookie (if any) gets the new token.
        $request->session()->regenerate();
        Auth::guard('web')->logoutOtherDevices($password);
        $sessions->endAll($user, keepSessionId: $request->session()->getId());

        return $this->back($user, 'Password changed. You were signed out everywhere else, including the mobile app.');
    }

    private function back(User $user, string $message): RedirectResponse
    {
        return redirect($user->settingsUrl('my-account'))->with('section', 'account')->with('status', $message);
    }

    /**
     * Runs an AccountChanges call; its errors ("code": wrong, expired, wait before resending; "email") show on the
     * email form.
     *
     * @param  Closure(): string  $action
     */
    private function inEmailForm(User $user, Closure $action): string
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($exception->errors())
                ->errorBag('accountEmail')
                ->redirectTo($user->settingsUrl('my-account'));
        }
    }
}
