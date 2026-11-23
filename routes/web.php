<?php

use App\Http\Controllers\AdminFileController;
use App\Http\Controllers\AppAuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GuestAuthController;
use App\Http\Controllers\HomeRequestController;
use App\Http\Controllers\LiveTicketController;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\MyBookingsController;
use App\Http\Controllers\MyRewardsController;
use App\Http\Controllers\MyWarrantyController;
use App\Http\Controllers\PasskeyLoginController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReferralLinkController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SiteController;
use App\Models\User;
use App\Support\Panels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Site pages: one React shell, with per-page meta tags
Route::controller(SiteController::class)->group(function () {
    Route::get('/', 'home');
    Route::get('/services', 'services');
    Route::get('/services/{slug}', 'service')->where('slug', '[a-z0-9-]+');
    Route::get('/branches', 'branches');
    Route::get('/branches/{slug}', 'branch')->where('slug', '[a-z0-9-]+');
    Route::get('/gallery', 'gallery');
    Route::get('/book', 'book');
    Route::get('/home-service', 'homeService');
    Route::get('/bookings', 'myBookings');
    Route::get('/bookings/{booking}', 'myBooking')->whereNumber('booking');
    // The customer's sign-in page and account pages
    Route::get('/login', 'login');
    Route::get('/account/{section?}', 'account')->where('section', 'garage|garage/[0-9]+|rewards|warranty|me');
});

// A shared referral link: the booking page with the friend's code filled in
Route::get('/r/{code}', ReferralLinkController::class)->name('referral.link');

// Sign in with Google, for customers (never the admin)
Route::controller(GuestAuthController::class)->group(function () {
    Route::get('/auth/google', 'redirect')->middleware('throttle:google')->name('auth.google');
    Route::get('/auth/google/callback', 'callback')->middleware('throttle:google')->name('auth.google.callback');
    Route::post('/auth/logout', 'logout')->name('auth.logout');
    // Local development and the demo only (404 anywhere else): the sample customer without Google
    Route::get('/auth/local', 'local')->name('auth.local');
});

// The customer endpoints below take the site's session cookie or the app's bearer token (guest.token)
Route::middleware('guest.token')->group(function () {
    // Open days for the picked services
    Route::get('/api/availability', [AvailabilityController::class, 'month'])->middleware('throttle:availability')->name('availability.month');

    Route::post('/api/bookings', [ReservationController::class, 'store'])->middleware('throttle:bookings')->name('bookings.store');
    Route::post('/api/home-requests', [HomeRequestController::class, 'store'])->middleware('throttle:bookings')->name('home-requests.store');

    // The signed-in customer's saved units, for repeat bookings, and one unit with its history
    Route::get('/api/my-vehicles', [MyBookingsController::class, 'vehicles'])->middleware('throttle:my-bookings')->name('my-vehicles.index');
    Route::get('/api/my-vehicles/{vehicle}', [MyBookingsController::class, 'vehicle'])->whereNumber('vehicle')->middleware('throttle:my-bookings')->name('my-vehicles.show');

    // Points, the friends who used the code, and the warranty on finished jobs
    Route::get('/api/my-rewards', [MyRewardsController::class, 'show'])->middleware('throttle:my-bookings')->name('my-rewards.show');
    Route::get('/api/my-warranty', [MyWarrantyController::class, 'show'])->middleware('throttle:my-bookings')->name('my-warranty.show');

    // The signed-in customer's referral code and what it has earned
    Route::get('/api/my-referral', [ReferralController::class, 'show'])->middleware('throttle:my-bookings')->name('my-referral.show');

    // The signed-in customer's own details (same answers as the app's /api/app/me), and deleting the account
    Route::get('/api/me', [AppAuthController::class, 'me'])->middleware(['guest.token:required', 'throttle:my-bookings'])->name('me.show');
    Route::patch('/api/me', [AppAuthController::class, 'update'])->middleware(['guest.token:required', 'throttle:my-bookings'])->name('me.update');
    Route::post('/api/me/delete', [AppAuthController::class, 'delete'])->middleware(['guest.token:required', 'throttle:my-bookings'])->name('me.delete');

    // My bookings: paying the reservation fee, cancelling, moving
    Route::prefix('api/my-bookings')->controller(MyBookingsController::class)->group(function () {
        Route::get('/', 'index')->middleware('throttle:my-bookings')->name('my-bookings.index');
        Route::get('/{booking}', 'show')->whereNumber('booking')->middleware('throttle:my-bookings')->name('my-bookings.show');
        Route::get('/{booking}/proof', 'proof')->whereNumber('booking')->middleware('throttle:my-bookings')->name('my-bookings.proof');
        Route::post('/{booking}/payment', 'payment')->whereNumber('booking')->middleware('throttle:payments')->name('my-bookings.payment');
        Route::post('/{booking}/cancel', 'cancel')->whereNumber('booking')->middleware('throttle:bookings')->name('my-bookings.cancel');
        Route::post('/{booking}/reschedule', 'reschedule')->whereNumber('booking')->middleware('throttle:bookings')->name('my-bookings.reschedule');
        Route::post('/{booking}/review', [ReviewController::class, 'store'])->whereNumber('booking')->middleware('throttle:reviews')->name('my-bookings.review');
    });
});

// A ticket for Radiance Flow's WebSocket (team in /admin, or a signed-in customer): live updates
Route::get('/live/ticket', LiveTicketController::class)->middleware(['guest.token', 'throttle:live-ticket'])->name('live.ticket');

// The team's view of a payment screenshot (signed in to /admin)
Route::get('/admin-files/bookings/{booking}/proof', [AdminFileController::class, 'proof'])
    ->whereNumber('booking')
    ->name('admin.bookings.proof');

// Sign in to /admin with a passkey: the one-time challenge, then the signed answer
Route::prefix('admin-passkeys')->controller(PasskeyLoginController::class)->group(function () {
    Route::get('/options', 'options')->middleware('throttle:passkey-options')->name('passkeys.options');
    Route::post('/login', 'login')->middleware('throttle:passkey-login')->name('passkeys.login');
});

// The old single admin: any other /admin address (an emailed link, a bookmark) goes to the same page in the
// signed-in person's own panel, or to sign-in first (the sign-in page, password reset and /admin itself are the panel's)
Route::get('/admin/{path}', function (Request $request) {
    $user = Auth::user();

    return $user instanceof User ? redirect(Panels::equivalentUrl($user, $request)) : redirect()->guest(url('/admin/login'));
})->where('path', '.*')->fallback();

// The Android app: its page, and the APK of a published release
Route::get('/download', [DownloadController::class, 'page'])->name('download');
Route::get('/download/826-auto-{version}.apk', [DownloadController::class, 'apk'])->where('version', '[0-9][0-9A-Za-z.]*')->name('download.apk');
Route::get('/download/826-team-{version}.apk', [DownloadController::class, 'staffApk'])->where('version', '[0-9][0-9A-Za-z.]*')->name('download.staff-apk');

Route::permanentRedirect('/booking', '/book');
Route::permanentRedirect('/reserve', '/book');
Route::permanentRedirect('/prices', '/services');
Route::permanentRedirect('/locations', '/branches');

// Messenger messages for each branch's Facebook Page: Meta checks the URL (GET), then signs every event (POST)
Route::controller(MetaWebhookController::class)->middleware('throttle:300,1')->group(function () {
    Route::get('/webhooks/meta', 'verify')->name('webhooks.meta.verify');
    Route::post('/webhooks/meta', 'receive')->name('webhooks.meta');
});

// For search engines (public/robots.txt would shadow this route)
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);

Route::fallback([SiteController::class, 'notFound']);
