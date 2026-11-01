<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Registration (web and API): short burst limit plus an hourly cap, per IP.
        // COR uploads get their own bucket so a student who re-uploads isn't locked out of the last step.
        RateLimiter::for('register-cor', fn (Request $request) => $this->perIpLimits(
            $request, 'register-cor', 'Too many COR uploads.',
        ));

        RateLimiter::for('register', fn (Request $request) => $this->perIpLimits(
            $request, 'register', 'Too many sign-up attempts.',
        ));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::transliterate(Str::lower($request->string('login')->trim()).'|'.$request->ip()))
            ->response(fn (Request $request, array $headers) => $this->tooManyAttempts(
                $request, $headers, 'Too many sign-in attempts.', 'login',
            )));
    }

    /**
     * @return list<Limit>
     */
    private function perIpLimits(Request $request, string $name, string $message): array
    {
        $tooMany = fn (Request $request, array $headers) => $this->tooManyAttempts($request, $headers, $message);

        return [
            Limit::perMinute(5)->by("{$name}:minute:".$request->ip())->response($tooMany),
            Limit::perHour(20)->by("{$name}:hour:".$request->ip())->response($tooMany),
        ];
    }

    /**
     * JSON 429 for the API; for the web, back to the form with the message.
     *
     * @param  array<string, string|int>  $headers
     */
    private function tooManyAttempts(Request $request, array $headers, string $message, string $errorKey = 'form'): Response
    {
        $message .= ' Please try again in '.$headers['Retry-After'].' seconds.';

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message], 429, $headers);
        }

        return back()
            ->withInput($request->except('password', 'password_confirmation'))
            ->withErrors([$errorKey => $message]);
    }
}
