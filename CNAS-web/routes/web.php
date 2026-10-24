<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.land-page')->name('landing');
Route::view('/login', 'pages.login-page')->name('login');
