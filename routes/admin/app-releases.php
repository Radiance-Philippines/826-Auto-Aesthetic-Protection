<?php

use App\Http\Controllers\ConsoleAppReleaseController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleAppReleaseController::class)
    ->middleware(ConsoleScreen::class.':app-releases')
    ->prefix('app-releases')
    ->name('app-releases.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        // POST, not PATCH: PHP reads a file upload only from a POST
        Route::post('{id}', 'update')->whereNumber('id')->name('update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
    });
