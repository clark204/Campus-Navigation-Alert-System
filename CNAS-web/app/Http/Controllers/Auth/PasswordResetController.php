<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordRecovery\ForgotPasswordRequest;
use App\Http\Requests\PasswordRecovery\ResetCodeRequest;
use App\Http\Requests\PasswordRecovery\ResetPasswordRequest;
use App\Services\EmailCodes;
use App\Services\PasswordRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Forgot password?" on the sign-in page (web): the email or student ID → a 6-digit code to the account's email
 * (verified or not) → the code, checked on its own → then the new password (the reset page in two steps). Every
 * answer is the same whether or not the account exists. The login typed on the first page waits in the session for
 * the reset page. Someone who can't open their email goes to the administrator (pages/no-email-access-page).
 */
class PasswordResetController extends Controller
{
    /** When the last code was asked for (session), for the "Resend code" countdown. */
    private const SENT_AT = 'password_reset.sent_at';

    public function __construct(private PasswordRecovery $recovery) {}

    public function create(Request $request): View
    {
        return view('pages.forgot-password-page', [
            'login' => old('login', $request->session()->get(PasswordRecovery::SESSION_LOGIN)),
        ]);
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $login = trim($request->validated('login'));
        $this->recovery->requestReset($login);

        $request->session()->put(PasswordRecovery::SESSION_LOGIN, $login);
        $request->session()->put(self::SENT_AT, now()->timestamp);
        // A new start: any code confirmed earlier (maybe for another account) no longer counts.
        $request->session()->forget(PasswordRecovery::SESSION_GRANT);

        return redirect()->route('password.reset')->with('status', PasswordRecovery::SENT_MESSAGE);
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $login = $request->session()->get(PasswordRecovery::SESSION_LOGIN);

        if (! is_string($login)) {
            return redirect()->route('password.request');
        }

        $grant = $request->session()->get(PasswordRecovery::SESSION_GRANT);

        return view('pages.reset-password-page', [
            'login' => $login,
            // Step 2 (new password) only once the code was right and the grant is still valid.
            'codeConfirmed' => is_string($grant) && $this->recovery->hasGrant($grant),
            'resendIn' => $this->secondsUntilResend($request),
        ]);
    }

    /**
     * Step 1: the code on its own. A right code opens step 2 on the same page.
     */
    public function verifyCode(ResetCodeRequest $request): RedirectResponse
    {
        $grant = $this->recovery->verifyResetCode($request->validated('login'), $request->validated('code'));
        $request->session()->put(PasswordRecovery::SESSION_GRANT, $grant);

        return redirect()->route('password.reset')->with('status', 'Code confirmed. Now choose your new password.');
    }

    /**
     * Step 2: the new password. An expired grant goes back to step 1 with the message.
     */
    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $login = $request->session()->get(PasswordRecovery::SESSION_LOGIN);

        try {
            $this->recovery->resetWithGrant($request->validated('reset_token'), $request->validated('password'));
        } catch (ValidationException $exception) {
            $request->session()->forget(PasswordRecovery::SESSION_GRANT);

            throw $exception;
        }

        $request->session()->forget([PasswordRecovery::SESSION_LOGIN, PasswordRecovery::SESSION_GRANT, self::SENT_AT]);

        return redirect()->route('login')
            ->withInput(['login' => $login])
            ->with('status', 'Password changed. Sign in with your new password.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $login = $request->session()->get(PasswordRecovery::SESSION_LOGIN);

        if (! is_string($login)) {
            return redirect()->route('password.request');
        }

        // The wait is enforced here too, without saying whether an account exists.
        if ($this->secondsUntilResend($request) === 0) {
            $this->recovery->requestReset($login);
            $request->session()->put(self::SENT_AT, now()->timestamp);
        }

        return redirect()->route('password.reset')->with('status', PasswordRecovery::SENT_MESSAGE);
    }

    private function secondsUntilResend(Request $request): int
    {
        $sentAt = (int) $request->session()->get(self::SENT_AT, 0);

        return max(0, $sentAt + EmailCodes::RESEND_SECONDS - now()->timestamp);
    }
}
