<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's points: what their referral code earned, minus what the
 * team has redeemed for them. Redeeming for a discount needs the owner's
 * value per point (Settings → Referral); without it the team can still
 * adjust points with a note. Never more than the balance.
 */
final class Rewards
{
    /**
     * Spends points. With a booking, takes points × the owner's peso value
     * off its price (never more than the price).
     *
     * @throws ValidationException
     */
    public static function redeem(Guest $guest, User $by, int $points, ?Booking $booking = null, ?string $note = null): RewardRedemption
    {
        return DB::transaction(function () use ($guest, $by, $points, $booking, $note) {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->getKey());
            $pesos = ReferralSetting::instance()->peso_per_point;

            if ($points < 1) {
                self::fail('points', 'Enter at least 1 point.');
            }

            if ($points > $guest->pointsBalance()) {
                self::fail('points', "They only have {$guest->pointsBalance()} points.");
            }

            $value = null;

            if ($booking) {
                $booking = Booking::query()->visibleTo($by)->lockForUpdate()->findOrFail($booking->getKey());
                $value = self::discountFor($guest, $booking, $points, $pesos);
                $booking->update([
                    'total_price' => $booking->total_price - $value,
                    'discount_amount' => (int) $booking->discount_amount + $value,
                ]);
            } elseif (blank($note)) {
                self::fail('note', 'Say why: what the points were used for.');
            }

            return RewardRedemption::create([
                'guest_id' => $guest->id,
                'points' => $points,
                'peso_value' => $value,
                'booking_id' => $booking?->id,
                'note' => filled($note) ? $note : null,
                'created_by' => $by->id,
            ]);
        });
    }

    /**
     * The most points that can still go on this booking.
     */
    public static function maxPointsFor(Guest $guest, Booking $booking): int
    {
        $pesos = ReferralSetting::current()->peso_per_point;

        if (! $pesos || $booking->total_price === null) {
            return 0;
        }

        return min($guest->pointsBalance(), intdiv((int) $booking->total_price, $pesos));
    }

    /**
     * Earned and spent lines, newest first.
     *
     * @return Collection<string, array{date: string, line: string, points: int, pesos: int|null, by: string|null, at: int}>
     */
    public static function ledger(Guest $guest): Collection
    {
        $earned = $guest->referralRedemptions()->whereNull('voided_at')->where('points_awarded', '>', 0)->with(['usedBy', 'booking'])->get()
            ->mapWithKeys(fn ($redemption) => ["e{$redemption->id}" => [
                'date' => ($redemption->awarded_at ?? $redemption->created_at)->format('M j, Y'),
                'line' => 'Earned: '.($redemption->usedBy?->name ?? 'a friend').' used their code'.($redemption->booking ? " ({$redemption->booking->reference})" : ''),
                'points' => (int) $redemption->points_awarded,
                'pesos' => null,
                'by' => null,
                'at' => ($redemption->awarded_at ?? $redemption->created_at)->getTimestamp(),
            ]]);

        $spent = $guest->rewardRedemptions()->with(['booking', 'createdBy'])->get()
            ->mapWithKeys(fn (RewardRedemption $redemption) => ["s{$redemption->id}" => [
                'date' => $redemption->created_at->format('M j, Y'),
                'line' => 'Redeemed'.($redemption->booking ? " on {$redemption->booking->reference}" : '').($redemption->note ? ": {$redemption->note}" : ''),
                'points' => -$redemption->points,
                'pesos' => $redemption->peso_value,
                'by' => $redemption->createdBy?->name,
                'at' => $redemption->created_at->getTimestamp(),
            ]]);

        return $earned->merge($spent)->sortByDesc('at');
    }

    private static function discountFor(Guest $guest, Booking $booking, int $points, ?int $pesos): int
    {
        if (! $pesos) {
            self::fail('booking_id', 'Set what a point is worth in Settings → Referral first, or leave the booking out and add a note.');
        }

        if ($booking->guest_id !== $guest->id || ! $booking->isOpen()) {
            self::fail('booking_id', 'Pick one of this customer’s open bookings.');
        }

        if ($booking->total_price === null) {
            self::fail('booking_id', 'That booking has no price yet. Review its unit first.');
        }

        $max = intdiv((int) $booking->total_price, $pesos);

        if ($points > $max) {
            self::fail('points', "That’s more than the booking’s price: at most {$max} points fit.");
        }

        return $points * $pesos;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
