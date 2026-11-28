<?php

use App\Http\Controllers\ConsoleServiceController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleServiceController::class)
    ->middleware(ConsoleScreen::class.':services')
    ->prefix('services')
    ->name('services.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('form', 'form')->name('form');
        Route::post('reorder', 'reorder')->name('reorder');
        Route::get('{service}', 'show')->whereNumber('service')->name('show');
        // POST, not PATCH: the form carries the picture
        Route::post('{service}', 'update')->whereNumber('service')->name('update');
        Route::delete('{service}', 'destroy')->whereNumber('service')->name('destroy');
    });
