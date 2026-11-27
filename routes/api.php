<?php

use App\Http\Controllers\AppAuthController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\StaffAppsController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\StaffCustomerController;
use App\Http\Controllers\StaffInboxController;
use App\Http\Controllers\StaffOverviewController;
use App\Http\Controllers\StaffQueueController;
use App\Http\Controllers\StaffSalesController;
use App\Http\Controllers\StaffSystemController;
use App\Http\Middleware\AppPin;
use Illuminate\Support\Facades\Route;

/*
| The customer app's own endpoints (served under /api/app). No session and no
| CSRF token: a signed-in call carries `Authorization: Bearer <token>`. The
| customer endpoints the site already uses (/api/my-bookings, /api/bookings…)
| live in routes/web.php and accept the same token.
*/
Route::prefix('app')->name('app.')->middleware(AppPin::class)->group(function () {
    Route::post('sign-in/google', [AppAuthController::class, 'google'])->middleware('throttle:app-sign-in')->name('sign-in.google');
    Route::post('sign-in/apple', [AppAuthController::class, 'apple'])->middleware('throttle:app-sign-in')->name('sign-in.apple');
    Route::post('sign-in/demo', [AppAuthController::class, 'demo'])->middleware('throttle:app-sign-in')->name('sign-in.demo');

    // Email and password sign-up and sign-in: the same rules as the site's /auth/*, answered with a bearer token
    Route::prefix('auth')->name('auth.')->controller(CustomerAuthController::class)->group(function () {
        Route::post('register', 'register')->middleware('throttle:customer-register')->name('register');
        Route::post('login', 'login')->middleware('throttle:customer-login')->name('login');
        Route::post('forgot-password', 'forgotPassword')->middleware('throttle:customer-forgot')->name('forgot');
        Route::post('reset-password', 'resetPassword')->middleware('throttle:customer-forgot')->name('reset');
        Route::post('resend-verification', 'resendVerification')->middleware('throttle:customer-forgot')->name('resend');
    });

    Route::get('catalog', [AppController::class, 'catalog'])->middleware('throttle:availability')->name('catalog');
    Route::get('showcase', [AppController::class, 'showcase'])->middleware('throttle:availability')->name('showcase');
    // Open, so an older app can still learn there is an update
    Route::get('latest', [AppController::class, 'latest'])->middleware('throttle:availability')->withoutMiddleware(AppPin::class)->name('latest');

    Route::middleware(['guest.token:required', 'throttle:my-bookings'])->group(function () {
        Route::post('sign-out', [AppAuthController::class, 'signOut'])->name('sign-out');
        Route::get('me', [AppAuthController::class, 'me'])->name('me');
        Route::patch('me', [AppAuthController::class, 'update'])->name('me.update');
        Route::get('home', [AppController::class, 'home'])->name('home');
        Route::post('push-token', [AppAuthController::class, 'pushToken'])->name('push-token');
        Route::post('account/delete', [AppAuthController::class, 'delete'])->name('account.delete');
    });
});

/*
| The staff app's ("826 Team") endpoints, under /api/staff. Signed in with a
| bearer token (user.token); no session, no CSRF. Every endpoint enforces
| the admin's areas and branch scopes on the server.
*/
Route::prefix('staff')->name('staff.')->group(function () {
    Route::post('sign-in', [StaffAuthController::class, 'signIn'])->middleware('throttle:staff-sign-in')->name('sign-in');
    Route::post('sign-in/two-factor', [StaffAuthController::class, 'twoFactor'])->middleware('throttle:staff-sign-in')->name('sign-in.two-factor');
    Route::get('latest', [StaffAuthController::class, 'latest'])->middleware('throttle:availability')->name('latest');

    Route::middleware(['user.token', 'throttle:staff'])->group(function () {
        Route::post('sign-out', [StaffAuthController::class, 'signOut'])->name('sign-out');
        Route::get('me', [StaffAuthController::class, 'me'])->name('me');

        Route::get('queue', [StaffQueueController::class, 'index'])->name('queue');
        Route::get('bookings/{id}', [StaffQueueController::class, 'show'])->whereNumber('id')->name('bookings.show');
        Route::post('bookings/{id}/stage', [StaffQueueController::class, 'stage'])->whereNumber('id')->name('bookings.stage');
        Route::post('bookings/{id}/ready-date', [StaffQueueController::class, 'readyDate'])->whereNumber('id')->name('bookings.ready-date');
        Route::post('bookings/{id}/notes', [StaffQueueController::class, 'note'])->whereNumber('id')->name('bookings.notes');
        Route::post('bookings/{id}/photos', [StaffQueueController::class, 'photo'])->whereNumber('id')->name('bookings.photos');
        Route::get('bookings/{id}/proof', [StaffQueueController::class, 'proof'])->whereNumber('id')->name('bookings.proof');

        Route::get('today', [StaffSalesController::class, 'today'])->name('today');
        Route::get('vehicle-sizes', [StaffSalesController::class, 'vehicleSizes'])->name('vehicle-sizes');
        Route::post('bookings/{id}/size', [StaffSalesController::class, 'size'])->whereNumber('id')->name('bookings.size');
        Route::post('bookings/{id}/payment/approve', [StaffSalesController::class, 'approvePayment'])->whereNumber('id')->name('bookings.payment.approve');
        Route::post('bookings/{id}/payment/reject', [StaffSalesController::class, 'rejectPayment'])->whereNumber('id')->name('bookings.payment.reject');

        Route::get('customers', [StaffCustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{id}', [StaffCustomerController::class, 'show'])->whereNumber('id')->name('customers.show');
        Route::patch('customers/{id}', [StaffCustomerController::class, 'update'])->whereNumber('id')->name('customers.update');

        Route::get('inbox', [StaffInboxController::class, 'index'])->name('inbox');
        Route::post('inbox', [StaffInboxController::class, 'start'])->name('inbox.start');
        Route::get('inbox/quick-replies', [StaffInboxController::class, 'quickReplies'])->name('inbox.quick-replies');
        Route::get('inbox/{id}', [StaffInboxController::class, 'show'])->whereNumber('id')->name('inbox.show');
        Route::post('inbox/{id}/reply', [StaffInboxController::class, 'reply'])->whereNumber('id')->name('inbox.reply');
        Route::post('inbox/{id}/done', [StaffInboxController::class, 'done'])->whereNumber('id')->name('inbox.done');

        Route::get('overview', [StaffOverviewController::class, 'show'])->name('overview');
        Route::get('system', [StaffSystemController::class, 'show'])->name('system');
        Route::get('apps', [StaffAppsController::class, 'index'])->name('apps');
    });
});
