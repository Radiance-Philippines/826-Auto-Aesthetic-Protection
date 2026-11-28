<?php

use App\Http\Controllers\ConsoleBranchServiceController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleBranchServiceController::class)
    ->middleware(ConsoleScreen::class.':branch-services')
    ->prefix('branch-services')
    ->name('branch-services.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('{branch}', 'update')->whereNumber('branch')->name('update');
    });
