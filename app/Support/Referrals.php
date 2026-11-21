<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Referral codes. Every customer can have a code (made the first time it is
 * needed). A signed-in customer with no earlier booking can use someone
 * else's code on a reservation; the discount shows once the booking is
 * priced, and the code's owner earns the owner's points when that booking
 * exits. A cancelled, declined or expired booking voids it.
 */
final class Referrals
{
    /**
     * Letters and digits that can't be misread over the phone.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * The customer's code, made on first need: their first name in capitals,
     * a dash and 4 characters ("MARIA-7K3Q").
     */
    public static function codeFor(Guest $guest): string
    {
        if (filled($guest->referral_code)) {
            return $guest->referral_code;
        }

        $name = preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii(Str::before(trim($guest->name), ' '))));
        $name = substr($name !== '' ? $name : 'FRIEND', 0, 12);

        do {
            $suffix = '';

            for ($i = 0; $i < 4; $i++) {
                $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $code = "{$name}-{$suffix}";
        } while (Guest::query()->where('referral_code', $code)->exists());

        $guest->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * Whose code this is, checked: switched on, used by a signed-in
     * customer who has no earlier booking, and never their own. Throws a
     * validation error on referral_code with a plain message.
     */
    public static function ownerFor(?Guest $guest, string $code): Guest
    {
        if (! ReferralSetting::current()->isActive()) {
            self::fail('Referral codes aren’t being accepted right now. Clear the box to continue.');
        }

        if (! $guest) {
            self::fail('Sign in to use a referral code.');
        }

        $owner = Guest::query()->where('referral_code', strtoupper(preg_replace('/\s+/', '', $code)))->first();

        if (! $owner) {
            self::fail('We couldn’t find that referral code. Check it and try again.');
        }

        if ($owner->is($guest)) {
            self::fail('You can’t use your own referral code.');
        }

        if (self::hasEarlierBooking($guest)) {
            self::fail('Referral codes are for your first booking only.');
        }

        return $owner;
    }

    /**
     * A booking that fell through (cancelled, declined, expired, or an
     * unpaid hold that ran out) doesn't count as an earlier one.
     */
    public static function hasEarlierBooking(Guest $guest): bool
    {
        return $guest->bookings()
            ->whereNotIn('status', ['cancelled', 'declined', 'expired'])
            ->where(fn ($booking) => $booking
                ->where('status', '!=', 'awaiting_payment')
                ->orWhereNull('hold_expires_at')
                ->orWhere('hold_expires_at', '>', now()))
            ->exists();
    }

    /**
     * Records that the booking used the owner's code. The discount comes
     * when the booking is priced.
     */
    public static function redeem(Booking $booking, Guest $owner): ReferralRedemption
    {
        $booking->update(['referral_guest_id' => $owner->id]);

        return ReferralRedemption::create([
            'code_guest_id' => $owner->id,
            'used_by_guest_id' => $booking->guest_id,
            'booking_id' => $booking->id,
        ]);
    }

    /**
     * The discount on a priced booking: what the owner's terms take off the
     * subtotal (none if the booking used no code). Kept on the redemption too.
     * shortcut: uses today's terms, not the terms when the code was entered; snapshot them if the owner starts changing them often.
     */
    public static function discountFor(Booking $booking, ?int $subtotal): ?int
    {
        $discount = $booking->referral_guest_id && $subtotal !== null ? ReferralSetting::current()->discountFor($subtotal) : null;

        $booking->referralRedemption?->update(['discount_amount' => $discount]);

        return $discount ?: null;
    }

    /**
     * Points are awarded once, when the booking exits; a booking that falls
     * through voids the redemption.
     */
    public static function syncStatus(Booking $booking): void
    {
        $redemption = $booking->referralRedemption;

        if (! $redemption || $redemption->awarded_at || $redemption->voided_at) {
            return;
        }

        if (in_array($booking->status, ['cancelled', 'declined', 'expired'], true)) {
            $redemption->update(['voided_at' => now()]);
        } elseif ($booking->status === 'exited') {
            $redemption->update(['points_awarded' => (int) ReferralSetting::current()->owner_points, 'awarded_at' => now()]);
        }
    }

    /**
     * What the customer's code has done, for My bookings.
     *
     * @return array{usedCount: int, pointsEarned: int, pointsPending: int}
     */
    public static function stats(Guest $guest): array
    {
        $redemptions = $guest->referralRedemptions()->whereNull('voided_at')->with('booking:id,status')->get();
        $pending = $redemptions->filter(fn (ReferralRedemption $redemption) => $redemption->awarded_at === null && in_array($redemption->booking?->status, Booking::OPEN, true));

        return [
            'usedCount' => $redemptions->count(),
            'pointsEarned' => (int) $redemptions->sum('points_awarded'),
            'pointsPending' => $pending->count() * (int) ReferralSetting::current()->owner_points,
        ];
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['referral_code' => $message]);
    }
}
