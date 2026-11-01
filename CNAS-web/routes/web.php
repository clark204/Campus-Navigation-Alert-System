<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Support\Facades\Route;

// Open to everyone, signed in or not.
Route::view('/navigate', 'pages.navigate-page')->name('navigate');

Route::middleware(['auth', EnsureUserIsAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/academic-terms', [AcademicTermController::class, 'index'])->name('academic-terms.index');
    Route::post('/academic-terms', [AcademicTermController::class, 'store'])->name('academic-terms.store');
    Route::post('/academic-terms/{academicTerm}/current', [AcademicTermController::class, 'makeCurrent'])->name('academic-terms.current');
});

// Design preview only: once sign-in works, add auth + a role check and take the role from the signed-in user.
Route::view('/student', 'portal.dashboard-page', ['role' => UserRole::Student])->name('student.dashboard');
Route::view('/faculty', 'portal.dashboard-page', ['role' => UserRole::Faculty])->name('faculty.dashboard');

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
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
