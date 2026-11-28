<?php

use App\Http\Controllers\ConsolePhotoController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsolePhotoController::class)
    ->middleware(ConsoleScreen::class.':photos')
    ->prefix('photos')
    ->name('photos.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('bulk', 'bulk')->name('bulk');
        Route::post('reorder', 'reorder')->name('reorder');
        Route::get('{photo}', 'show')->whereNumber('photo')->name('show');
        // POST, not PATCH: the form can carry a new picture
        Route::post('{photo}', 'update')->whereNumber('photo')->name('update');
        Route::patch('{photo}/featured', 'feature')->whereNumber('photo')->name('feature');
        Route::delete('{photo}', 'destroy')->whereNumber('photo')->name('destroy');
    });
