<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordRecovery\ChangeTemporaryPasswordRequest;
use App\Services\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Choose a new password": after signing in with a temporary password from the admin (users.must_change_password,
 * set by Admin > Accounts), the portal waits until the user picks their own (EnsurePasswordIsChanged).
 */
class TemporaryPasswordController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route($request->user()->nextRoute());
        }

        return view('pages.change-password-page');
    }

    public function update(ChangeTemporaryPasswordRequest $request, UserSessions $sessions): RedirectResponse
    {
        $user = $request->user();

        $user->password = $request->validated('password');
        $user->forceFill(['must_change_password' => false]);
        $user->setRememberToken(Str::random(60));
        $user->save();

        // This browser stays signed in; anywhere else that used the temporary password is signed out.
        $request->session()->regenerate();
        $sessions->endAll($user, keepSessionId: $request->session()->getId());

        // Next stop: "Verify your email" if it's still waiting, otherwise their home.
        return redirect()->route($user->nextRoute())
            ->with('toast', ['title' => 'Password changed', 'body' => 'Use it the next time you sign in.']);
    }
}
