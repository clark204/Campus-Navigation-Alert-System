<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only users with one of the given roles may continue. Use after `auth`, which sends guests to the sign-in page.
 */
class EnsureUserHasRole
{
    /**
     * Middleware string for a route, e.g. EnsureUserHasRole::using(UserRole::Faculty, UserRole::Staff).
     */
    public static function using(UserRole ...$roles): string
    {
        return static::class.':'.implode(',', array_map(fn (UserRole $role): string => $role->value, $roles));
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->user()?->role?->value, $roles, true), 403);

        return $next($request);
    }
}
