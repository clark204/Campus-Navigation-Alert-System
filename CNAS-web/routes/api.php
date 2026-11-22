<?php

use App\Http\Controllers\Api\ActiveAlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\EmergencyContactController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\SafetyCheckController;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Support\Facades\Route;

// Stateless (no session or cookies): the mobile app keeps the draft token between the two calls.
Route::post('/register/cor', [RegisterController::class, 'storeCor'])
    ->middleware('throttle:register-cor')
    ->name('api.register.cor');
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:register')
    ->name('api.register');

// Emergency banner: polled every 15 seconds by every page (guests included) and the mobile app. Cached (Alert::current).
Route::get('/alerts/active', ActiveAlertController::class)->name('api.alerts.active');

// The campus map every map draws (web pages, guests included, and the mobile app). Cached until the map changes
// (CampusMap::payload); the ETag lets a client that already has it get a 304. Not rate limited, like the alert
// poll above: the whole campus can share one IP address.
Route::get('/map', MapController::class)->middleware(SetCacheHeaders::using('etag'))->name('api.map');

// Emergency numbers (the portal home list), for the mobile app. Public; cached on the server until a contact changes,
// and for 5 minutes on the phone (the ETag lets it get a 304 after that).
Route::get('/emergency-contacts', EmergencyContactController::class)
    ->middleware(SetCacheHeaders::using('public;max_age=300;etag'))
    ->name('api.emergency-contacts');

// Forgotten passwords (docs/registration.md, "Password recovery"). Stateless: the app keeps the login, then the reset
// grant from verify-code, between calls.
Route::controller(PasswordRecoveryController::class)->prefix('password')->name('api.password.')->group(function () {
    Route::post('/forgot', 'forgot')->middleware('throttle:password-forgot')->name('forgot');
    Route::post('/verify-code', 'verifyCode')->middleware('throttle:password-reset')->name('verify-code');
    Route::post('/reset', 'reset')->middleware('throttle:password-reset')->name('reset');
});

// Phone alert push (docs/alerts.md, "Phone push"). Public, like web push: guests get alerts too. A valid bearer token
// links the phone to that account; DELETE is "Turn off alerts" in the app.
Route::controller(PushTokenController::class)->prefix('push-tokens')->name('api.push-tokens.')
    ->middleware('throttle:push-tokens')
    ->group(function () {
        Route::post('/', 'store')->name('store');
        Route::delete('/', 'destroy')->name('destroy');
    });

// Sanctum bearer tokens, one per device.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

    // "Verify your email" (docs/registration.md): the app shows its verify screen while the user's email_verified is false.
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:email-code-verify')->name('api.email.verify');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-code-send')->name('api.email.resend');

    // The signed-in student's classes for the current term, each with its room on the map when there is one.
    Route::get('/schedule', ScheduleController::class)->name('api.schedule');

    // "I'm safe" from the app's takeover or banner. The web controller, answering JSON: 409 when the alert is over or
    // isn't an emergency. Unverified users can answer, like on the web.
    Route::post('/alerts/{alert}/safe', SafetyCheckController::class)->middleware('throttle:10,1')->name('api.alerts.safe');
});
