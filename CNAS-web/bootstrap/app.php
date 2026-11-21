<?php

use App\Http\Middleware\TrackLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust tunnels like ngrok so asset URLs use https.
        $middleware->trustProxies(at: '*');

        // "Active now" on the admin dashboard: signed-in web and app requests update users.last_seen_at.
        $middleware->web(append: [TrackLastSeen::class]);
        $middleware->api(append: [TrackLastSeen::class]);

        // Signed-in users who open a guest page (landing, login, register, forgot password) go to their role's home,
        // or first to "Choose a new password" / "Verify your email" (User::nextRoute).
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->nextRoute()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A 360° photo bigger than php.ini's post_max_size is refused before the session starts, so the upload page
        // can't get a normal form error: send the admin back to it with a flag that shows the size limit.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->is('admin/panoramas') && ! $request->expectsJson()) {
                return redirect()->route('admin.panoramas.index', ['too_large' => 1]);
            }
        });
    })->create();
