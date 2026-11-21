<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal pages: a user signed in with a temporary password from the admin (users.must_change_password) goes to
 * "Choose a new password" first. Runs before EnsureEmailIsVerified, so the temporary password is replaced before
 * anything else. Use after `auth`. The same pages stay open as for an unverified email (routes/web.php).
 */
class EnsurePasswordIsChanged
{
    public const MESSAGE = 'Choose a new password first.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return $request->expectsJson()
                ? response()->json(['message' => self::MESSAGE], 403)
                : redirect()->route('password.change');
        }

        return $next($request);
    }
}
