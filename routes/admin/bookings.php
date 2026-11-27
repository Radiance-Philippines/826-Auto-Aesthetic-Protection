<?php

use App\Http\Controllers\ConsoleBookingController;
use App\Http\Controllers\StaffQueueController;
use App\Http\Controllers\StaffSalesController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::middleware(ConsoleScreen::class.':bookings')->prefix('bookings')->name('bookings.')->group(function () {
    Route::controller(ConsoleBookingController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('form', 'form')->name('form');
        Route::post('mark', 'markMany')->name('mark');
        Route::post('delete', 'destroyMany')->name('destroy-many');

        Route::whereNumber('id')->prefix('{id}')->group(function () {
            Route::get('/', 'show')->name('show');
            Route::patch('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::get('edit', 'edit')->name('edit');
            Route::post('unread', 'markUnread')->name('unread');
            Route::post('cancel', 'cancel')->name('cancel');
            Route::post('no-show', 'noShow')->name('no-show');
            Route::get('dates', 'dates')->name('dates');
            Route::post('reschedule', 'reschedule')->name('reschedule');
            Route::post('schedule', 'schedule')->name('schedule');
        });
    });

    // The staff app's own actions: their area, the person's branches, the same rules
    Route::whereNumber('id')->prefix('{id}')->group(function () {
        Route::post('payment/approve', [StaffSalesController::class, 'approvePayment'])->name('payment.approve');
        Route::post('payment/reject', [StaffSalesController::class, 'rejectPayment'])->name('payment.reject');
        Route::post('size', [StaffSalesController::class, 'size'])->name('size');
        Route::post('stage', [StaffQueueController::class, 'stage'])->name('stage');
        Route::post('ready-date', [StaffQueueController::class, 'readyDate'])->name('ready-date');
        Route::post('assigned-to', [StaffQueueController::class, 'assignedTo'])->name('assigned-to');
    });
});
