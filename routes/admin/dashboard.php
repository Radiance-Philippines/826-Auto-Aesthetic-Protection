<?php

use App\Http\Controllers\ConsoleDashboardController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleDashboardController::class)
    ->middleware(ConsoleScreen::class.':dashboard')
    ->prefix('dashboard')
    ->group(function () {
        Route::get('/', 'index')->name('dashboard');
        Route::get('export', 'export')->name('dashboard.export');
    });
