<?php

use App\Http\Controllers\ConsoleQueueController;
use App\Http\Controllers\StaffQueueController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::middleware(ConsoleScreen::class.':queue')->prefix('queue')->group(function () {
    Route::get('/', [ConsoleQueueController::class, 'index'])->name('queue');

    // The staff app's own actions: queue.update, the person's branches, one step at a time
    Route::post('{id}/stage', [StaffQueueController::class, 'stage'])->whereNumber('id')->name('queue.stage');
    Route::post('{id}/assigned-to', [StaffQueueController::class, 'assignedTo'])->whereNumber('id')->name('queue.assigned-to');
});
