<?php

use App\Http\Controllers\ConsoleProfileController;
use Illuminate\Support\Facades\Route;

// The signed-in person's own profile: no screen to check. While two-factor sign-in is required and not
// set up, only `show` and the `setup.` routes open (OpenConsolePanel). The throttle is for the actions
// that check a password or a code.
Route::controller(ConsoleProfileController::class)
    ->prefix('profile')
    ->name('profile.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::patch('/', 'update')->middleware('throttle:console-profile')->name('update');

        Route::post('two-factor/app', 'startApp')->name('setup.app');
        Route::post('two-factor/app/confirm', 'confirmApp')->middleware('throttle:console-profile')->name('setup.app.confirm');
        Route::delete('two-factor/app', 'disableApp')->middleware('throttle:console-profile')->name('app.disable');
        Route::post('two-factor/recovery-codes', 'recoveryCodes')->middleware('throttle:console-profile')->name('recovery-codes');

        Route::post('two-factor/email/code', 'sendEmailCode')->name('setup.email.code');
        Route::post('two-factor/email', 'enableEmail')->middleware('throttle:console-profile')->name('setup.email');
        Route::delete('two-factor/email', 'disableEmail')->middleware('throttle:console-profile')->name('email.disable');

        Route::post('passkeys/options', 'passkeyOptions')->name('passkeys.options');
        Route::post('passkeys', 'addPasskey')->name('passkeys.store');
        Route::patch('passkeys/{passkey}', 'renamePasskey')->whereNumber('passkey')->name('passkeys.rename');
        Route::delete('passkeys/{passkey}', 'removePasskey')->whereNumber('passkey')->name('passkeys.remove');
    });
