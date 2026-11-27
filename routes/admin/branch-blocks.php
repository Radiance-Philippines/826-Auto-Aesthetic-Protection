<?php

use App\Http\Controllers\ConsoleBranchBlockController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleBranchBlockController::class)
    ->middleware(ConsoleScreen::class.':branch-blocks')
    ->prefix('branch-blocks')
    ->name('branch-blocks.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::patch('{id}', 'update')->whereNumber('id')->name('update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
    });
