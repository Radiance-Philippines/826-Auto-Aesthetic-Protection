<?php

use App\Http\Controllers\ConsoleManualTransactionController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleManualTransactionController::class)
    ->middleware(ConsoleScreen::class.':manual-transaction')
    ->prefix('manual-transaction')
    ->name('manual-transaction.')
    ->group(function () {
        Route::get('/', 'form')->name('form');
        Route::post('/', 'store')->name('store');
        Route::get('rates', 'rates')->name('rates');
        Route::get('customers', 'customers')->name('customers');
    });
