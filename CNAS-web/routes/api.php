<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegisterController;
use Illuminate\Support\Facades\Route;

// Stateless (no session or cookies): the mobile app keeps the draft token between the two calls.
Route::post('/register/cor', [RegisterController::class, 'storeCor'])
    ->middleware('throttle:register-cor')
    ->name('api.register.cor');
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:register')
    ->name('api.register');

// Sanctum bearer tokens, one per device.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
});
