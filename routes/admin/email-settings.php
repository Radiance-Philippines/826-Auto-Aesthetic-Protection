<?php

use App\Http\Controllers\ConsoleEmailSettingController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleEmailSettingController::class)
    ->middleware(ConsoleScreen::class.':email-settings')
    ->prefix('email-settings')
    ->name('email-settings.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'update')->name('update');
        Route::post('test', 'test')->name('test');
    });
