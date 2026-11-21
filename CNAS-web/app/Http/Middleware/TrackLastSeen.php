<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes when a signed-in user was last seen, for "Active now" on the admin dashboard. Writes at most
 * once a minute per user (User::markSeen). Appended to the web and api groups in bootstrap/app.php.
 */
class TrackLastSeen
{
    /**
     * Checks the user after the request, because route middleware such as auth:sanctum runs after the
     * group middleware and is what signs in an API request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            $user->markSeen();
        }

        return $response;
    }
}
