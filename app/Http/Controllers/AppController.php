<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\AppRelease;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Support\GoogleSignIn;
use App\Support\Referrals;
use Illuminate\Http\JsonResponse;

/**
 * What the customer app opens with: its home screen, the shop's catalog
 * and booking rules, and the newest Android release. See routes/api.php.
 */
class AppController extends Controller
{
    use ReadsBookingInput;

    /**
     * GET /api/app/home: the unit in the shop now, the next booking, the
     * referral card and the garage.
     */
    public function home(): JsonResponse
    {
        $guest = $this->guest();

        $open = $guest->bookings()
            ->whereIn('status', Booking::OPEN)
            ->with(['items', 'branch', 'review'])
            ->get()
            ->filter(fn (Booking $booking) => $booking->isOpen());

        $inShop = $open->filter->isInShop()->sortByDesc(fn (Booking $booking) => $booking->arrived_at?->getTimestamp() ?? 0)->first();

        $next = $open
            ->reject(fn (Booking $booking) => $booking->isInShop())
            ->sortBy(fn (Booking $booking) => ($booking->drop_off_on ?? $booking->preferred_date)?->toDateString() ?? '9999-12-31')
            ->first();

        $referral = ReferralSetting::current();

        return response()->json([
            'guest' => $guest->toApp(),
            'inShop' => $inShop?->toCustomer(),
            'nextBooking' => $next?->toCustomer(),
            'referral' => [
                'enabled' => $referral->isActive(),
                'code' => $referral->isActive() ? Referrals::codeFor($guest) : $guest->referral_code,
                'discountLabel' => $referral->label(),
            ],
            'garage' => $guest->vehicles()->orderBy('model')->orderBy('id')->limit(6)->get()->map->toCustomer()->values(),
            'unread' => 0,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/app/catalog: everything the booking wizard needs, as the
     * site gets it (no prices; the price comes after the admin reviews the
     * unit), and which sign-in buttons to show.
     */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'signIn' => [
                'google' => GoogleSignIn::appEnabled(),
                'apple' => GoogleSignIn::appleEnabled(),
                'demo' => Guest::demoSignInAllowed(),
            ],
            'branches' => Branch::query()->where('status', 'open')->ordered()->get()->map->toSite()->values(),
            ...SiteController::catalog(),
            'vehicleTypes' => collect(Booking::VEHICLE_TYPES)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'booking' => SiteController::bookingRules(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/app/latest: the newest published Android release, or
     * versionCode 0 when there is none yet.
     */
    public function latest(): JsonResponse
    {
        $release = AppRelease::latestPublished();

        return response()->json($release?->toApp() ?? [
            'version' => null,
            'versionCode' => 0,
            'url' => null,
            'sha256' => null,
            'notes' => null,
            'mandatory' => false,
        ])->header('Cache-Control', 'no-store');
    }
}
