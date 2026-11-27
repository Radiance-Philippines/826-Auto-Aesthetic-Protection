<?php

use App\Http\Controllers\ConsoleBackjobTypeController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleBackjobTypeController::class)
    ->middleware(ConsoleScreen::class.':backjob-types')
    ->prefix('backjob-types')
    ->name('backjob-types.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('reorder', 'reorder')->name('reorder');
        Route::patch('{backjobType}', 'update')->whereNumber('backjobType')->name('update');
        Route::delete('{backjobType}', 'destroy')->whereNumber('backjobType')->name('destroy');
    });
