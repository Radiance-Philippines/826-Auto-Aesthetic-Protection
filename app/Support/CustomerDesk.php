<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSize;

/**
 * The team's side of a customer, shared by the Filament resource and the
 * React admin.
 */
final class CustomerDesk
{
    /**
     * @return array<int, string>
     */
    public static function salesRepOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Why this customer can't be deleted, or null.
     */
    public static function keepReason(Guest $guest): ?string
    {
        return $guest->bookings()->exists() ? 'This customer has bookings. Keep them, or move the bookings first.' : null;
    }

    /**
     * Open, priced bookings of theirs that points can come off.
     *
     * @return array<int, string>
     */
    public static function redeemableBookings(Guest $guest, ?User $user): array
    {
        return Booking::query()->visibleTo($user)->where('guest_id', $guest->id)->open()->whereNotNull('total_price')->latest('id')->get()
            ->mapWithKeys(fn (Booking $booking) => [$booking->id => "{$booking->reference} · ₱".number_format($booking->total_price)])->all();
    }

    public static function redeemDescription(): string
    {
        $pesos = ReferralSetting::current()->peso_per_point;

        return $pesos
            ? 'One point is worth ₱'.number_format($pesos).'. Pick a booking to take it off its price, or leave it out and add a note.'
            : 'Pesos per point isn’t set in Settings → Referral, so this only adjusts the points: say what they were used for.';
    }

    public static function pointsNote(Guest $guest): string
    {
        return ReferralSetting::current()->owner_points === null
            ? 'No points set in Settings → Referral.'
            : (int) $guest->referralRedemptions()->sum('points_awarded').' earned, '.(int) $guest->rewardRedemptions()->sum('points').' spent';
    }

    /**
     * "2 services are covered now", over the branches this person works at.
     */
    public static function warrantyLine(Guest $guest, ?User $user): string
    {
        $active = Warranty::search($user, '', $guest->id)
            ->sum(fn ($booking) => $booking->items->filter(fn ($item) => Warranty::status($booking, $item) === 'active')->count());

        return $active === 1 ? '1 service is covered now' : "{$active} services are covered now";
    }

    /**
     * The id of this customer's latest Messenger chat that the team member may open.
     */
    public static function chatId(Guest $guest, ?User $user): ?int
    {
        return Conversation::visibleTo($user)->where('guest_id', $guest->getKey())->latestFirst()->value('id');
    }

    /**
     * The unit's bookings, newest first, at the branches this user may see.
     *
     * @return list<array{id: int, reference: string, date: string, branch: string, services: string, status: string, price: string}>
     */
    public static function unitJobs(Vehicle $unit, ?User $user): array
    {
        return $unit->bookings()
            ->visibleTo($user)
            ->with(['branch', 'items'])
            ->orderByRaw('coalesce(exited_at, drop_off_on, preferred_date, created_at) desc')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->getKey(),
                'reference' => $booking->reference,
                'date' => ($booking->exited_at ?? $booking->drop_off_on ?? $booking->preferred_date ?? $booking->created_at)->format('M j, Y'),
                'branch' => (string) $booking->branch?->name,
                'services' => $booking->servicesLabel() ?: '—',
                'status' => BookingDesk::statusLabel($booking),
                'price' => $booking->total_price === null ? 'Size needed' : '₱'.number_format($booking->total_price),
            ])
            ->all();
    }

    /**
     * Gives a unit its size class, prices its open bookings that were
     * waiting and tells the customer. Returns how many were priced.
     */
    public static function setUnitSize(Vehicle $unit, VehicleSize $size): int
    {
        $priced = Reservations::setVehicleSize($unit, $size);

        foreach ($priced as $booking) {
            Notifier::customer($booking, 'priced');
        }

        return count($priced);
    }
}
