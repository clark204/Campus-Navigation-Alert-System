<?php

namespace App\Http\Controllers\Auth;

use App\Enums\EmailCodePurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmEmailCodeRequest;
use App\Http\Requests\Account\CorrectUnverifiedEmailRequest;
use App\Services\EmailCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Verify your email" (web): enter the 6-digit code emailed after registering or signing in, or ask for a new one.
 * Portal pages send unverified users here (EnsureEmailIsVerified). Verified users and the admin go to their home.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private EmailCodes $codes) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->needsEmailVerification()) {
            return redirect()->route($user->role->homeRoute());
        }

        $code = $this->codes->current($user, EmailCodePurpose::VerifyEmail);

        return view('pages.verify-email-page', [
            'email' => $user->email,
            'codeIsUsable' => $this->codes->usable($code),
            'resendIn' => $this->codes->secondsUntilResend($user, EmailCodePurpose::VerifyEmail),
        ]);
    }

    public function store(ConfirmEmailCodeRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->needsEmailVerification()) {
            $this->codes->verifyAccountEmail($user, $request->validated('code'));
        }

        // A toast on the dashboard (partials/toasts); Settings also shows "Verified" next to the email.
        return redirect()->route($user->role->homeRoute())->with('toast', [
            'title' => 'Email verified',
            'body' => 'Welcome to TCC NAV, '.$user->first_name.'!',
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->needsEmailVerification()) {
            $this->codes->send($user, EmailCodePurpose::VerifyEmail);
        }

        return redirect()->route('email.verify')->with('status', "We sent a new code to {$user->email}.");
    }

    /**
     * "Wrong email?": while the email isn't verified, the user may fix it here (it was never confirmed, so nothing is
     * lost) and gets a code at the new address. Verified accounts change their email in Settings instead.
     */
    public function updateEmail(CorrectUnverifiedEmailRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->needsEmailVerification()) {
            return redirect()->route($user->role->homeRoute());
        }

        $user->forceFill(['email' => $request->validated('email')])->save();

        // The old code went to the wrong address; the per-user rate limit still caps how often this can send.
        $this->codes->forget($user, EmailCodePurpose::VerifyEmail);
        $this->codes->send($user, EmailCodePurpose::VerifyEmail);

        return redirect()->route('email.verify')->with('status', "Email changed. We sent a new code to {$user->email}.");
    }
}
