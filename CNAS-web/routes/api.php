<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActiveAlertController;
use App\Http\Controllers\Api\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\Admin\ConcernController as AdminConcernController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\AlertManagementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConcernController as ApiConcernController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\EmergencyContactController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\PlaceController as ApiPlaceController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\ConcernController;
use App\Http\Controllers\NeedHelpController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\SafetyCheckController;
use App\Http\Middleware\EnsureUserCanSendAlerts;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsAdmin;
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

    // "My schedule" (students) and "My classes" (faculty), like the web portal (docs/schedule.md). Reading is open to
    // every role (staff and the admin just get no classes); changing them is for students and faculty, like the web.
    Route::controller(ScheduleController::class)->prefix('schedule')->group(function () {
        Route::get('/', 'index')->name('api.schedule');
        Route::middleware(EnsureUserHasRole::using(UserRole::Student, UserRole::Faculty))->name('api.schedule.')->group(function () {
            Route::get('/rooms', 'rooms')->name('rooms');
            Route::post('/', 'store')->name('store');
            Route::put('/{classSchedule}', 'update')->name('update');
            Route::delete('/{classSchedule}', 'destroy')->name('destroy');
            Route::post('/import', 'import')->middleware([EnsureUserHasRole::using(UserRole::Student), 'throttle:10,1'])->name('import');
        });
    });

    // Account → Change email and Change password, like Settings → My account on the web (same requests, rules and
    // limits; docs/registration.md "Email verification"). A new email is only saved after the code sent to it.
    Route::controller(AccountController::class)->prefix('account')->name('api.account.')->group(function () {
        Route::get('/email', 'email')->name('email');
        Route::put('/email', 'updateEmail')->middleware('throttle:settings-email-code-send')->name('email.update');
        Route::put('/email/confirm', 'confirmEmail')->middleware('throttle:settings-email-code-verify')->name('email.confirm');
        Route::post('/email/resend', 'resendEmailCode')->middleware('throttle:settings-email-code-send')->name('email.resend');
        Route::delete('/email', 'cancelEmail')->name('email.cancel');
        Route::put('/password', 'updatePassword')->middleware('throttle:6,1')->name('password');
    });

    // The portal's Saved places, Help & contact and the bell, for students, faculty and staff (docs/portal.md). Saving,
    // Recent and sending a concern use the web's own controllers, which answer JSON.
    Route::middleware(EnsureUserHasRole::using(UserRole::Student, UserRole::Faculty, UserRole::Staff))->group(function () {
        Route::get('/places', ApiPlaceController::class)->name('api.places');
        Route::controller(PlaceController::class)->prefix('places')->name('api.places.')->middleware('throttle:60,1')->group(function () {
            Route::delete('/recent', 'clearRecent')->name('recent.clear');
            Route::post('/{room}/save', 'save')->name('save');
            Route::delete('/{room}/save', 'unsave')->name('unsave');
            Route::post('/{room}/recent', 'recent')->name('recent');
        });

        Route::get('/concerns', ApiConcernController::class)->name('api.concerns');
        Route::post('/concerns', [ConcernController::class, 'store'])->middleware('throttle:5,10')->name('api.concerns.store');

        Route::controller(NotificationController::class)->prefix('notifications')->name('api.notifications.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/read', 'readAll')->name('read-all');
            Route::post('/{notification}/read', 'read')->name('read');
        });
    });

    // Sending and ending alerts from the app: the admin and the faculty and staff the admin trusts, like the web
    // (docs/alerts.md). The same requests and Alert methods, so everything is logged and pushed the same way. Editing
    // the wording or level and the "I'm safe" live view are the admin's (UpdateAlertRequest, EnsureUserIsAdmin).
    Route::controller(AlertManagementController::class)->prefix('alerts')->name('api.alerts.')
        ->middleware(EnsureUserCanSendAlerts::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/form', 'form')->name('form');
            Route::post('/', 'store')->name('store');
            Route::put('/{alert}', 'update')->name('update');
            Route::post('/{alert}/resolve', 'resolve')->name('resolve');
            Route::get('/{alert}/safety', 'safety')->middleware(EnsureUserIsAdmin::class)->name('safety');
        });

    // Admin > Concerns and Admin > Accounts for the app (admin only), with the web's services and requests
    // (ConcernInbox, AccountAdministration; docs/portal.md, docs/accounts.md).
    Route::middleware(EnsureUserIsAdmin::class)->prefix('admin')->name('api.admin.')->group(function () {
        Route::controller(AdminConcernController::class)->prefix('concerns')->name('concerns.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{concern}', 'show')->name('show');
            Route::post('/{concern}/replies', 'reply')->name('reply');
            Route::patch('/{concern}/resolve', 'resolve')->name('resolve');
            Route::patch('/{concern}/reopen', 'reopen')->name('reopen');
        });

        // The app's dashboard: the website's numbers (AdminAnalytics).
        Route::get('/dashboard', AdminDashboardController::class)->middleware('throttle:30,1')->name('dashboard');

        // The app's Settings: the web's requests and messages (docs/settings.md). Web-only: map data, photos, QR codes.
        Route::controller(AdminSettingsController::class)->name('settings.')->group(function () {
            Route::get('/academic-terms', 'terms')->name('terms');
            Route::post('/academic-terms', 'storeTerm')->name('terms.store');
            Route::post('/academic-terms/{academicTerm}/current', 'makeTermCurrent')->name('terms.current');
            Route::get('/contact-details', 'contactDetails')->name('contact');
            Route::put('/contact-details', 'updateContactDetails')->name('contact.update');
            Route::get('/emergency-contacts', 'emergencyContacts')->name('emergency-contacts');
            Route::post('/emergency-contacts', 'storeEmergencyContact')->name('emergency-contacts.store');
            Route::put('/emergency-contacts/{emergencyContact}', 'updateEmergencyContact')->name('emergency-contacts.update');
            Route::delete('/emergency-contacts/{emergencyContact}', 'destroyEmergencyContact')->name('emergency-contacts.destroy');
            Route::patch('/emergency-contacts/{emergencyContact}/move', 'moveEmergencyContact')->name('emergency-contacts.move');
            Route::patch('/emergency-contacts/{emergencyContact}/visibility', 'emergencyContactVisibility')->name('emergency-contacts.visibility');
            Route::get('/system-status', 'status')->middleware('throttle:30,1')->name('status');
        });

        Route::controller(AdminAccountController::class)->prefix('accounts')->name('accounts.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/options', 'options')->name('options');
            Route::post('/', 'store')->name('store');
            Route::get('/{user}', 'show')->name('show');
            Route::put('/{user}', 'update')->name('update');
            Route::patch('/{user}/trust', 'trust')->name('trust');
            Route::patch('/{user}/password', 'password')->name('password');
            Route::patch('/{user}/role', 'role')->name('role');
            Route::delete('/{user}', 'destroy')->name('destroy');
        });
    });

    // "I'm safe" from the app's takeover or banner. The web controller, answering JSON: 409 when the alert is over or
    // isn't an emergency. Unverified users can answer, like on the web.
    Route::post('/alerts/{alert}/safe', SafetyCheckController::class)->middleware('throttle:10,1')->name('api.alerts.safe');
    Route::post('/alerts/{alert}/help', NeedHelpController::class)->middleware('throttle:10,1')->name('api.alerts.help');
});
