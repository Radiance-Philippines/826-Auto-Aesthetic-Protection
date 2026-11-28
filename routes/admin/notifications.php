<?php

use App\Http\Controllers\ConsoleNotificationController;
use Illuminate\Support\Facades\Route;

// The bell in the shell: everyone who can open a panel reads their own, so there is no screen to check
Route::controller(ConsoleNotificationController::class)
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('read-all', 'readAll')->name('read-all');
        Route::post('{id}/read', 'read')->whereUuid('id')->name('read');
        Route::delete('/', 'clear')->name('clear');
        Route::delete('{id}', 'destroy')->whereUuid('id')->name('destroy');
    });
