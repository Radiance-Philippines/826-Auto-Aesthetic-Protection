<?php

namespace App\Support;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\Booking;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Links into the admin for one recipient: always in the panel that person
 * works in (/owner, /sales or /operations), never the one that happens to
 * be current when an email or bell notification is built.
 *
 *     AdminUrl::for($user)->booking($booking)
 */
final class AdminUrl
{
    private function __construct(private readonly string $panel) {}

    public static function for(?User $user): self
    {
        return new self(($user ? Panels::homeId($user) : null) ?? Panels::OWNER);
    }

    public function home(): string
    {
        return Filament::getPanel($this->panel)->getUrl();
    }

    public function booking(Booking|int $booking): string
    {
        return BookingResource::getUrl('view', ['record' => $booking], panel: $this->panel);
    }

    public function reviews(): string
    {
        return ReviewResource::getUrl('index', panel: $this->panel);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function inbox(array $parameters = []): string
    {
        return Inbox::getUrl($parameters, panel: $this->panel);
    }

    /**
     * For a message that goes to an address, not an account (a branch's
     * alert mailbox): /admin sends whoever opens it to their own panel and
     * keeps the page when it exists there.
     */
    public static function legacy(string $path = ''): string
    {
        return rtrim(url('/'.Panels::GATEWAY.'/'.ltrim($path, '/')), '/');
    }

    public static function legacyBooking(Booking|int $booking): string
    {
        return self::legacy('bookings/'.($booking instanceof Booking ? $booking->getKey() : $booking));
    }
}
