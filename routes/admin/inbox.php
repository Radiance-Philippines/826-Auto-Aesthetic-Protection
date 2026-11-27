<?php

use App\Http\Controllers\ConsoleInboxController;
use App\Http\Controllers\StaffInboxController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::middleware(ConsoleScreen::class.':inbox')->prefix('inbox')->name('inbox.')->group(function () {
    Route::get('/', [ConsoleInboxController::class, 'index'])->name('index');
    // "New team thread": the staff app's own action
    Route::post('/', [StaffInboxController::class, 'start'])->name('start');

    Route::whereNumber('id')->prefix('{id}')->group(function () {
        Route::controller(ConsoleInboxController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::get('team', 'team')->name('team');
            Route::post('assign', 'assign')->name('assign');
            Route::get('customers', 'customers')->name('customers');
            Route::post('customer', 'link')->name('link');
        });

        // The staff app's own actions: the same policy and App\Support\Inbox\Inbox
        Route::post('reply', [StaffInboxController::class, 'reply'])->name('reply');
        Route::post('done', [StaffInboxController::class, 'done'])->name('done');
    });
});
