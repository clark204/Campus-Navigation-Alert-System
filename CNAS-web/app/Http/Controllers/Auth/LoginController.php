<?php

namespace App\Http\Controllers\Auth;

use App\Enums\EmailCodePurpose;
use App\Enums\LoginChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\EmailCodes;
use App\Services\LoginActivityLogger;
use App\Services\LoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('pages.login-page');
    }

    public function store(LoginRequest $request, LoginService $logins, LoginActivityLogger $activity, EmailCodes $codes): RedirectResponse
    {
        $request->authenticate($logins);
        $request->session()->regenerate();

        $login = $activity->recordLogin($request->user(), LoginChannel::Web, $request);
        $request->session()->put(LoginActivityLogger::SESSION_KEY, $login->id);

        // Always the role's own dashboard, never the page that sent a guest here: that page may belong to another role.
        $request->session()->forget('url.intended');

        // Accounts the admin created, and older accounts, verify their email on their next sign-in. With a temporary
        // password they first choose their own (User::nextRoute); the code waits for them meanwhile.
        if ($request->user()->needsEmailVerification()) {
            $codes->sendUnlessPending($request->user(), EmailCodePurpose::VerifyEmail);
        }

        return redirect()->route($request->user()->nextRoute());
    }

    public function destroy(Request $request, LoginActivityLogger $activity): RedirectResponse
    {
        $activity->recordWebLogout($request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
