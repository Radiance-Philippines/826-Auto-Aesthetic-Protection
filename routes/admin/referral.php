<?php

use App\Http\Controllers\ConsoleReferralSettingController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleReferralSettingController::class)
    ->middleware(ConsoleScreen::class.':referral')
    ->prefix('referral')
    ->name('referral.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'update')->name('update');
    });
