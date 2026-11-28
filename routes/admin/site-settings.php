<?php

use App\Http\Controllers\ConsoleSiteSettingController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleSiteSettingController::class)
    ->middleware(ConsoleScreen::class.':site-settings')
    ->prefix('site-settings')
    ->name('site-settings.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        // POST: it may carry the two pictures
        Route::post('/', 'update')->name('update');
    });
