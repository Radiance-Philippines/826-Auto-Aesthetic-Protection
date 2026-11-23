<?php

use App\Http\Controllers\AppAuthController;
use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

/*
| The customer app's own endpoints (served under /api/app). No session and no
| CSRF token: a signed-in call carries `Authorization: Bearer <token>`. The
| customer endpoints the site already uses (/api/my-bookings, /api/bookings…)
| live in routes/web.php and accept the same token.
*/
Route::prefix('app')->name('app.')->group(function () {
    Route::post('sign-in/google', [AppAuthController::class, 'google'])->middleware('throttle:app-sign-in')->name('sign-in.google');
    Route::post('sign-in/apple', [AppAuthController::class, 'apple'])->middleware('throttle:app-sign-in')->name('sign-in.apple');
    Route::post('sign-in/demo', [AppAuthController::class, 'demo'])->middleware('throttle:app-sign-in')->name('sign-in.demo');

    Route::get('catalog', [AppController::class, 'catalog'])->middleware('throttle:availability')->name('catalog');
    Route::get('latest', [AppController::class, 'latest'])->middleware('throttle:availability')->name('latest');

    Route::middleware(['guest.token:required', 'throttle:my-bookings'])->group(function () {
        Route::post('sign-out', [AppAuthController::class, 'signOut'])->name('sign-out');
        Route::get('me', [AppAuthController::class, 'me'])->name('me');
        Route::patch('me', [AppAuthController::class, 'update'])->name('me.update');
        Route::get('home', [AppController::class, 'home'])->name('home');
        Route::post('push-token', [AppAuthController::class, 'pushToken'])->name('push-token');
        Route::post('account/delete', [AppAuthController::class, 'delete'])->name('account.delete');
    });
});
