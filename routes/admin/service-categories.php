<?php

use App\Http\Controllers\ConsoleServiceCategoryController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleServiceCategoryController::class)
    ->middleware(ConsoleScreen::class.':service-categories')
    ->prefix('service-categories')
    ->name('service-categories.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('reorder', 'reorder')->name('reorder');
        Route::patch('{category}', 'update')->whereNumber('category')->name('update');
        Route::delete('{category}', 'destroy')->whereNumber('category')->name('destroy');
    });
