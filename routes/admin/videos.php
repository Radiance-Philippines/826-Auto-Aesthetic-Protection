<?php

use App\Http\Controllers\ConsoleVideoController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleVideoController::class)
    ->middleware(ConsoleScreen::class.':videos')
    ->prefix('videos')
    ->name('videos.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('reorder', 'reorder')->name('reorder');
        Route::patch('{video}', 'update')->whereNumber('video')->name('update');
        Route::patch('{video}/published', 'publish')->whereNumber('video')->name('publish');
        Route::delete('{video}', 'destroy')->whereNumber('video')->name('destroy');
    });
