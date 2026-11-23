<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\AppRelease;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Photo;
use App\Models\ReferralSetting;
use App\Models\Review;
use App\Models\Video;
use App\Support\GoogleSignIn;
use App\Support\ImagePipeline;
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
     * GET /api/app/showcase: the Our work content the site shows (the
     * admin's videos, photos and approved reviews), with absolute image
     * URLs. Public, no aggregates.
     */
    public function showcase(): JsonResponse
    {
        $absolute = fn (array $image): array => [
            'src' => url($image['src']),
            'srcset' => $image['srcset'] === null ? null : preg_replace_callback('/(\S+)(\s+\d+w)/', fn (array $m) => url($m[1]).$m[2], $image['srcset']),
            'thumb' => url($image['thumb']),
        ];

        return response()->json([
            'videos' => Video::published()->ordered()->get()->map->toSite()->values(),
            'photos' => Photo::ordered()->get()->map(fn (Photo $photo) => [
                'id' => $photo->id,
                'alt' => $photo->caption ?: SiteController::SHORT_NAME.': '.strtolower(Photo::CATEGORIES[$photo->category] ?? 'our work'),
                'category' => $photo->category,
                'categoryLabel' => Photo::CATEGORIES[$photo->category] ?? null,
                'image' => $absolute(app(ImagePipeline::class)->urls($photo->image)),
            ])->values(),
            'reviews' => collect(Review::forSite())->map(fn (array $review) => [
                ...$review,
                'photo' => $review['photo'] === null ? null : $absolute($review['photo']),
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/app/latest: the newest published Android release, or
     * versionCode 0 when there is none yet.
     */
    public function latest(string $app = AppRelease::CUSTOMER): JsonResponse
    {
        $release = AppRelease::latestPublished($app);

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
