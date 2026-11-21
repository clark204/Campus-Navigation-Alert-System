<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only people who may send emergency alerts continue: the admin, or faculty and staff the admin trusts
 * (User::canSendAlerts). Use after `auth`. Turning trust off in Admin > Accounts takes effect on the next request.
 */
class EnsureUserCanSendAlerts
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canSendAlerts(), 403, 'The administrator hasn’t given you access to send emergency alerts.');

        return $next($request);
    }
}
