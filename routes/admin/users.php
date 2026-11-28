<?php

use App\Http\Controllers\ConsoleUserController;
use App\Http\Middleware\ConsoleScreen;
use Illuminate\Support\Facades\Route;

Route::controller(ConsoleUserController::class)
    ->middleware(ConsoleScreen::class.':users')
    ->prefix('users')
    ->name('users.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'invite')->name('invite');
        Route::patch('{id}', 'update')->whereNumber('id')->name('update');
        Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
        Route::post('{id}/resend-invite', 'resendInvite')->whereNumber('id')->name('resend-invite');
        Route::post('{id}/reset-two-factor', 'resetTwoFactor')->whereNumber('id')->name('reset-two-factor');
        Route::post('{id}/remove-passkeys', 'removePasskeys')->whereNumber('id')->name('remove-passkeys');
        Route::post('{id}/view-as', 'viewAs')->whereNumber('id')->name('view-as');
    });
