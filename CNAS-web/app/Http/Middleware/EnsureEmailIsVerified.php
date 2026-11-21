<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal pages: a signed-in user whose email isn't verified yet goes to "Verify your email" first
 * (User::needsEmailVerification; the admin never does). Use after `auth`.
 */
class EnsureEmailIsVerified
{
    public const MESSAGE = 'Verify your email address first.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->needsEmailVerification()) {
            return $request->expectsJson()
                ? response()->json(['message' => self::MESSAGE], 403)
                : redirect()->route('email.verify');
        }

        return $next($request);
    }
}
