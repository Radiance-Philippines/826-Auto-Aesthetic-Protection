<?php

use App\Http\Controllers\ConsoleCustomerController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleCustomerController::class)
    ->middleware(ConsoleScreen::class.':customers')
    ->prefix('customers')
    ->name('customers.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('form', 'form')->name('form');

        Route::whereNumber(['id', 'unit'])->prefix('{id}')->group(function () {
            Route::get('/', 'show')->name('show');
            Route::patch('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::get('edit', 'edit')->name('edit');
            Route::post('redeem', 'redeem')->name('redeem');
            Route::get('units/{unit}', 'history')->name('history');
            Route::post('units/{unit}/size', 'size')->name('size');
        });
    });
