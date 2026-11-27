<?php

use App\Http\Controllers\ConsoleVehicleSizeController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleVehicleSizeController::class)
    ->middleware(ConsoleScreen::class.':vehicle-sizes')
    ->prefix('vehicle-sizes')
    ->name('vehicle-sizes.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('reorder', 'reorder')->name('reorder');
        Route::patch('{vehicleSize}', 'update')->name('update');
        Route::delete('{vehicleSize}', 'destroy')->name('destroy');
    });
