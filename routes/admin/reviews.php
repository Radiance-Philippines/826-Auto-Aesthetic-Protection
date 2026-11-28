<?php

use App\Http\Controllers\ConsoleReviewController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleReviewController::class)
    ->middleware(ConsoleScreen::class.':reviews')
    ->prefix('reviews')
    ->name('reviews.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('delete', 'destroyMany')->name('destroy-many');

        Route::whereNumber('id')->prefix('{id}')->group(function () {
            Route::get('/', 'show')->name('show');
            Route::patch('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::post('approve', 'approve')->name('approve');
            Route::post('reject', 'reject')->name('reject');
            Route::post('feature', 'feature')->name('feature');
        });
    });
