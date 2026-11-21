<?php

use App\Enums\UserRole;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\BuildingController;
use App\Http\Controllers\Admin\CampusMapController;
use App\Http\Controllers\Admin\ConcernController as AdminConcernController;
use App\Http\Controllers\Admin\ContactDetailsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmergencyContactController;
use App\Http\Controllers\Admin\PanoramaController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TemporaryPasswordController;
use App\Http\Controllers\ConcernController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\Portal\AlertController as PortalAlertController;
use App\Http\Controllers\Portal\ClassScheduleController;
use App\Http\Controllers\Portal\ClassScheduleImportController;
use App\Http\Controllers\Portal\FacultyDashboardController;
use App\Http\Controllers\Portal\HelpController;
use App\Http\Controllers\Portal\SavedPlacesController;
use App\Http\Controllers\Portal\SettingsController as PortalSettingsController;
use App\Http\Controllers\Portal\StudentDashboardController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SafetyCheckController;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserCanSendAlerts;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Support\Facades\Route;

// Open to everyone, signed in or not.
Route::view('/navigate', 'pages.navigate-page')->name('navigate');

// "Turn on emergency alerts" (browser push). Guests subscribe anonymously; signed-in users are linked to their account.
Route::controller(PushSubscriptionController::class)->prefix('push-subscriptions')->name('push-subscriptions.')
    ->middleware('throttle:20,1')
    ->group(function () {
        Route::post('/', 'store')->name('store');
        Route::delete('/', 'destroy')->name('destroy');
    });

// The portal's Help & contact page and the site footer (guests): a concern, suggestion or feedback for Admin > Concerns.
Route::post('/concerns', [ConcernController::class, 'store'])->middleware('throttle:5,10')->name('concerns.store');

Route::middleware(['auth', EnsureUserIsAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Top bar search: the results page (Enter, or no JavaScript) and the suggestions while typing (docs/search.md).
    Route::get('/search', [SearchController::class, 'index'])->name('search');
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->middleware('throttle:60,1')->name('search.suggestions');

    Route::get('/academic-terms', [AcademicTermController::class, 'index'])->name('academic-terms.index');
    Route::post('/academic-terms', [AcademicTermController::class, 'store'])->name('academic-terms.store');
    Route::post('/academic-terms/{academicTerm}/current', [AcademicTermController::class, 'makeCurrent'])->name('academic-terms.current');

    // Settings: one page, four tabs (?tab=) that each save on their own (docs/settings.md).
    Route::get('/settings', SettingsController::class)->name('settings');
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::put('/contact', [ContactDetailsController::class, 'update'])->name('contact.update');

        Route::controller(EmergencyContactController::class)->prefix('emergency-contacts')->name('emergency-contacts.')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::put('/{emergencyContact}', 'update')->name('update');
            Route::delete('/{emergencyContact}', 'destroy')->name('destroy');
            Route::patch('/{emergencyContact}/move', 'move')->name('move');
            Route::patch('/{emergencyContact}/visibility', 'visibility')->name('visibility');
        });

        Route::controller(AccountController::class)->prefix('account')->name('account.')->group(function () {
            // A new email is only saved after the code sent to it is entered (docs/registration.md).
            Route::put('/email', 'updateEmail')->middleware('throttle:settings-email-code-send')->name('email');
            Route::put('/email/confirm', 'confirmEmail')->middleware('throttle:settings-email-code-verify')->name('email.confirm');
            Route::post('/email/resend', 'resendEmailCode')->middleware('throttle:settings-email-code-send')->name('email.resend');
            Route::delete('/email', 'cancelEmail')->name('email.cancel');
            Route::put('/password', 'updatePassword')->middleware('throttle:6,1')->name('password');
        });

        // Polled every 10 s while System status is open (resources/js/push-subscribers-live.js).
        Route::get('/push-subscribers', [SystemStatusController::class, 'pushSubscribers'])
            ->middleware('throttle:30,1')
            ->name('push-subscribers');
    });

    // Accounts: list, create, trust for alerts, delete after typing the email (docs/accounts.md).
    Route::controller(AdminAccountController::class)->prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{user}', 'show')->name('show');
        Route::get('/{user}/edit', 'edit')->name('edit');
        Route::put('/{user}', 'update')->name('update');
        Route::patch('/{user}/trust', 'trust')->name('trust');
        Route::patch('/{user}/password', 'password')->name('password');
        Route::patch('/{user}/role', 'role')->name('role');
        Route::delete('/{user}', 'destroy')->name('destroy');
    });

    Route::controller(AdminConcernController::class)->prefix('concerns')->name('concerns.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{concern}', 'show')->name('show');
        Route::post('/{concern}/replies', 'reply')->name('reply');
        Route::patch('/{concern}/resolve', 'resolve')->name('resolve');
        Route::patch('/{concern}/reopen', 'reopen')->name('reopen');
    });

    Route::controller(AlertController::class)->prefix('alerts')->name('alerts.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{alert}/edit', 'edit')->name('edit');
        Route::put('/{alert}', 'update')->name('update');
        Route::post('/{alert}/resolve', 'resolve')->name('resolve');
    });

    // Campus map data is edited in another app: here it is downloaded as campus.geojson and imported back.
    Route::controller(CampusMapController::class)->prefix('map')->name('map.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/export', 'export')->name('export');
        Route::post('/import', 'import')->name('import');
    });
    // Buildings and rooms: their names and photos are edited here. Shapes, floors and positions come from the other app.
    // Photos are nested under their place, so {photo} must belong to that building or room (scopeBindings).
    Route::controller(BuildingController::class)->prefix('buildings')->name('buildings.')->scopeBindings()->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{building}/edit', 'edit')->name('edit');
        Route::put('/{building}', 'update')->name('update');
        Route::post('/{building}/photos', 'storePhotos')->name('photos.store');
        Route::patch('/{building}/photos/{photo}/move', 'movePhoto')->name('photos.move');
        Route::delete('/{building}/photos/{photo}', 'destroyPhoto')->name('photos.destroy');
    });
    Route::controller(RoomController::class)->prefix('rooms')->name('rooms.')->scopeBindings()->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{room}/edit', 'edit')->name('edit');
        Route::put('/{room}', 'update')->name('update');
        Route::post('/{room}/photos', 'storePhotos')->name('photos.store');
        Route::patch('/{room}/photos/{photo}/move', 'movePhoto')->name('photos.move');
        Route::delete('/{room}/photos/{photo}', 'destroyPhoto')->name('photos.destroy');
    });

    // 360° photo points ({panorama} = pn3). The list holds the upload form's one-time key, so it is never shown from
    // the browser cache (see rooms.create); an upload is idempotent, so a double click adds one photo.
    Route::controller(PanoramaController::class)->prefix('panoramas')->name('panoramas.')->group(function () {
        Route::get('/', 'index')->middleware(SetCacheHeaders::using('no_store;private'))->name('index');
        Route::post('/', 'store')->middleware(EnsureIdempotentRequest::class)->name('store');
        // Every photo point, hidden ones too, for the debug panel on /navigate?debug=1 (why a photo is not on a route).
        Route::get('/route-debug', 'routeDebug')->name('route-debug');
        // The map editor (one page for every photo) and its "Save all" (one request for several photos).
        Route::get('/map', 'map')->name('map');
        Route::put('/batch', 'saveAll')->name('batch');
        Route::get('/{panorama}/edit', 'edit')->name('edit');
        Route::put('/{panorama}', 'update')->name('update');
        Route::patch('/{panorama}/visibility', 'visibility')->name('visibility');
        Route::delete('/{panorama}', 'destroy')->name('destroy');
    });
});

// Portal: $role picks the portal (its navigation and sections), not the user's own role. Staff share the faculty
// portal until they get their own (UserRole::homeRoute). Users with a temporary password from the admin go to "Choose a
// new password" first (EnsurePasswordIsChanged), then users who haven't verified their email to "Verify your email"
// (EnsureEmailIsVerified).
Route::middleware(['auth', EnsurePasswordIsChanged::class, EnsureEmailIsVerified::class])->group(function () {
    Route::middleware(EnsureUserHasRole::using(UserRole::Student))->prefix('student')->name('student.')->group(function () {
        Route::get('/', StudentDashboardController::class)->name('dashboard');

        // "My schedule": the student's own classes for the current term (docs/schedule.md).
        Route::prefix('schedule')->name('schedule.')->group(function () {
            Route::controller(ClassScheduleImportController::class)->group(function () {
                Route::get('/import', 'create')->name('import');
                Route::post('/import', 'store')->middleware('throttle:10,1')->name('import.store');
            });
            Route::controller(ClassScheduleController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{classSchedule}/edit', 'edit')->name('edit');
                Route::put('/{classSchedule}', 'update')->name('update');
                Route::delete('/{classSchedule}', 'destroy')->name('destroy');
            });
        });
    });
    Route::get('/faculty', FacultyDashboardController::class)
        ->middleware(EnsureUserHasRole::using(UserRole::Faculty, UserRole::Staff))
        ->name('faculty.dashboard');

    // "My classes": what a teacher teaches this term, added by hand (no COR for faculty). Faculty only: staff share the
    // faculty portal but don't teach (docs/schedule.md).
    Route::middleware(EnsureUserHasRole::using(UserRole::Faculty))
        ->controller(ClassScheduleController::class)->prefix('faculty/classes')->name('faculty.classes.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{classSchedule}/edit', 'edit')->name('edit');
            Route::put('/{classSchedule}', 'update')->name('update');
            Route::delete('/{classSchedule}', 'destroy')->name('destroy');
        });

    // "Emergency alerts" for faculty and staff the admin trusts (Admin > Accounts): send one and end the active one.
    Route::middleware([EnsureUserHasRole::using(UserRole::Faculty, UserRole::Staff), EnsureUserCanSendAlerts::class])
        ->controller(PortalAlertController::class)->prefix('faculty/alerts')->name('faculty.alerts.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/send', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::post('/{alert}/resolve', 'resolve')->name('resolve');
        });

    // Pages every portal has: student.settings, faculty.settings, … (docs/portal.md).
    $portals = ['student' => [UserRole::Student], 'faculty' => [UserRole::Faculty, UserRole::Staff]];
    foreach ($portals as $portal => $roles) {
        Route::middleware(EnsureUserHasRole::using(...$roles))->prefix($portal)->name("{$portal}.")->group(function () {
            Route::get('/settings', PortalSettingsController::class)->name('settings');
            Route::controller(AccountController::class)->prefix('settings/account')->name('settings.account.')->group(function () {
                // A new email is only saved after the code sent to it is entered (docs/registration.md).
                Route::put('/email', 'updateEmail')->middleware('throttle:settings-email-code-send')->name('email');
                Route::put('/email/confirm', 'confirmEmail')->middleware('throttle:settings-email-code-verify')->name('email.confirm');
                Route::post('/email/resend', 'resendEmailCode')->middleware('throttle:settings-email-code-send')->name('email.resend');
                Route::delete('/email', 'cancelEmail')->name('email.cancel');
                Route::put('/password', 'updatePassword')->middleware('throttle:6,1')->name('password');
            });

            Route::get('/saved', SavedPlacesController::class)->name('saved');
            Route::get('/help', HelpController::class)->name('help');
        });
    }

    // The notification bell in the top bar (admin and portals, partials/notification-bell).
    Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
        Route::post('/read', 'readAll')->name('read-all');
        Route::get('/{notification}', 'open')->name('open');
    });
});

// Guest-only pages: signed-in users go to their home (bootstrap/app.php, redirectUsersTo). no-store makes the
// back button reload them (and so redirect) instead of showing a cached copy after signing in.
Route::middleware(['guest', SetCacheHeaders::using('no_store;private')])->group(function () {
    Route::view('/', 'pages.land-page')->name('landing');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');

    // Registration: 1. upload COR → 2. create account (verified COR details + email and password).
    Route::controller(RegisterController::class)->group(function () {
        Route::get('/register', 'create')->name('register');
        Route::post('/register/cor', 'storeCor')->middleware('throttle:register-cor')->name('register.cor');
        Route::get('/register/confirm', 'confirm')->name('register.confirm');
        Route::post('/register', 'store')
            ->middleware(['throttle:register', EnsureIdempotentRequest::class])
            ->name('register.store');
    });

    // Forgot password: email or student ID → a code to the account's email → code + new password (docs/registration.md).
    Route::controller(PasswordResetController::class)->prefix('password')->name('password.')->group(function () {
        Route::get('/forgot', 'create')->name('request');
        Route::post('/forgot', 'store')->middleware('throttle:password-forgot')->name('email');
        Route::get('/reset', 'edit')->name('reset');
        Route::post('/reset/code', 'verifyCode')->middleware('throttle:password-reset')->name('verify-code');
        Route::post('/reset', 'update')->middleware('throttle:password-reset')->name('update');
        Route::post('/reset/resend', 'resend')->middleware('throttle:password-forgot')->name('resend');
    });

    // "Can't access your email?": go to the administrator with a school ID, or send them a message. No self-service
    // recovery: a COR can be copied or faked, so it can't prove who someone is (docs/registration.md).
    Route::view('/password/no-email', 'pages.no-email-access-page')->name('password.no-email');
});

// Signed in, verified email or not: unverified users can still sign out, verify, save places and answer "I'm safe".
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // "Choose a new password" after a temporary password from the admin (EnsurePasswordIsChanged sends users here).
    Route::get('/password/change', [TemporaryPasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [TemporaryPasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.change.update');

    // "Verify your email": the code emailed after registering or signing in. "Wrong email?" fixes a typo before the
    // address is verified, so a mistyped email at sign-up can't lock the student out.
    Route::controller(EmailVerificationController::class)->prefix('email/verify')->name('email.')->group(function () {
        Route::get('/', 'show')->name('verify');
        Route::post('/', 'store')->middleware('throttle:email-code-verify')->name('verify.store');
        Route::post('/resend', 'resend')->middleware('throttle:email-code-send')->name('verify.resend');
        Route::put('/email', 'updateEmail')->middleware('throttle:email-code-send')->name('verify.email');
    });

    // Saved and recent places: "Save" and Start on /navigate (fetch), and the portal's Saved places page.
    Route::controller(PlaceController::class)->prefix('places')->name('places.')->middleware('throttle:60,1')->group(function () {
        Route::delete('/recent', 'clearRecent')->name('recent.clear');
        Route::post('/{room}/save', 'save')->name('save');
        Route::delete('/{room}/save', 'unsave')->name('unsave');
        Route::post('/{room}/recent', 'recent')->name('recent');
    });

    // "I'm safe" from the alarm takeover or the banner.
    Route::post('/alerts/{alert}/safe', SafetyCheckController::class)->middleware('throttle:10,1')->name('alerts.safe');
});
