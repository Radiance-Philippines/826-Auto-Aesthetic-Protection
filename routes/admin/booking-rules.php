<?php

use App\Http\Controllers\ConsoleBookingRuleController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleBookingRuleController::class)
    ->middleware(ConsoleScreen::class.':booking-rules')
    ->prefix('booking-rules')
    ->name('booking-rules.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'update')->name('update');
    });
