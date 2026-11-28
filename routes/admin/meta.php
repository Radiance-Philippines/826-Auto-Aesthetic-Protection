<?php

use App\Http\Controllers\ConsoleMetaSettingController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleMetaSettingController::class)
    ->middleware(ConsoleScreen::class.':meta')
    ->prefix('meta')
    ->name('meta.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'update')->name('update');
        // Writes: both mark the token as shown
        Route::post('verify-token/reveal', 'revealToken')->name('reveal-token');
        Route::post('verify-token', 'newToken')->name('new-token');
    });
