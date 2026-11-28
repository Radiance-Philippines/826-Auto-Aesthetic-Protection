<?php

use App\Http\Controllers\ConsoleSecuritySettingController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleSecuritySettingController::class)
    ->middleware(ConsoleScreen::class.':security')
    ->prefix('security')
    ->name('security.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'update')->name('update');
    });
