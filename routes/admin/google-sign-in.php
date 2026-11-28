<?php

use App\Http\Controllers\ConsoleGoogleSignInController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleGoogleSignInController::class)
    ->middleware(ConsoleScreen::class.':google-sign-in')
    ->prefix('google-sign-in')
    ->name('google-sign-in.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'update')->name('update');
        Route::post('turn-off', 'turnOff')->name('turn-off');
    });
