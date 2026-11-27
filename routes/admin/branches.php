<?php

use App\Http\Controllers\ConsoleBranchController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleBranchController::class)
    ->middleware(ConsoleScreen::class.':branches')
    ->prefix('branches')
    ->name('branches.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('form', 'form')->name('form');
        Route::post('reorder', 'reorder')->name('reorder');

        Route::whereNumber('id')->prefix('{id}')->group(function () {
            Route::get('/', 'show')->name('show');
            // POST, not PATCH: the form carries the QR files
            Route::post('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::post('connect', 'connect')->name('connect');
        });
    });
