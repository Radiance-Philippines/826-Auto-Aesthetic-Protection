<?php

use App\Http\Controllers\ConsolePaymentSettingController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsolePaymentSettingController::class)
    ->middleware(ConsoleScreen::class.':payment-settings')
    ->prefix('payment-settings')
    ->name('payment-settings.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        // POST, not PATCH: the form carries the QR files
        Route::post('/', 'update')->name('update');
    });
