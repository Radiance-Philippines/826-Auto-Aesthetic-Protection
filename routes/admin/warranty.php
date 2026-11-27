<?php

use App\Http\Controllers\ConsoleWarrantyController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleWarrantyController::class)
    ->middleware(ConsoleScreen::class.':warranty')
    ->prefix('warranty')
    ->name('warranty.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('backjobs', 'store')->name('store');
        Route::post('backjobs/{id}/done', 'done')->whereNumber('id')->name('done');
    });
