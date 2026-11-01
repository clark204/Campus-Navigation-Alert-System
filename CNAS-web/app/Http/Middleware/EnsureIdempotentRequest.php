<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a form POST safe to repeat (double-click, refresh, flaky network).
 *
 * The form carries a one-time `idempotency_key`. The first request holding a key
 * runs; any repeat of that key waits for it, then gets the same redirect back
 * instead of running the action again. Failed attempts (validation errors) are
 * not remembered, so the user can fix the form and resubmit with the same key.
 */
class EnsureIdempotentRequest
{
    private const TTL_SECONDS = 86400;

    private const LOCK_SECONDS = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->input('idempotency_key');

        if (! is_string($key) || ! Str::isUuid($key)) {
            // Drop the bad key so the form renders with a fresh one.
            return $this->reject($request, 'Your form expired. Please try again.', ['idempotency_key']);
        }

        $cacheKey = 'idempotency:'.$request->route()->getName().':'.$key;

        try {
            return Cache::lock($cacheKey.':lock', self::LOCK_SECONDS)
                ->block(self::LOCK_SECONDS / 2, function () use ($request, $next, $cacheKey) {
                    if ($stored = Cache::get($cacheKey)) {
                        return redirect($stored['to'])->with($stored['flash']);
                    }

                    $response = $next($request);

                    // Only flash data from this request counts; `errors` from the
                    // previous failed attempt is still readable in the session.
                    $flashed = $request->session()->get('_flash.new', []);

                    if ($response instanceof RedirectResponse && ! in_array('errors', $flashed, true)) {
                        Cache::put($cacheKey, [
                            'to' => $response->getTargetUrl(),
                            'flash' => $request->session()->only($flashed),
                        ], self::TTL_SECONDS);
                    }

                    return $response;
                });
        } catch (LockTimeoutException) {
            // Keep the key: once the first request finishes, a retry replays its result.
            return $this->reject($request, 'Your request is still being processed. Please wait a moment.');
        }
    }

    /**
     * @param  array<int, string>  $alsoExcept
     */
    private function reject(Request $request, string $message, array $alsoExcept = []): RedirectResponse
    {
        return back()
            ->withInput($request->except(['password', 'password_confirmation', ...$alsoExcept]))
            ->withErrors(['form' => $message]);
    }
}
