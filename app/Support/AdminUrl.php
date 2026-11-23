<?php

namespace App\Support;

use App\Filament\Pages\Inbox;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\Booking;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Links into the admin for one recipient: always in a panel of that person's
 * that has the page (a sales admin's in /sales, a super admin's bookings and
 * inbox in /sales too), never the one that happens to be current when an
 * email or bell notification is built.
 *
 *     AdminUrl::for($user)->booking($booking)
 */
final class AdminUrl
{
    /**
     * @param  list<string>  $panels  the recipient's panels, home first
     */
    private function __construct(private readonly array $panels) {}

    public static function for(?User $user): self
    {
        return new self(($user ? Panels::idsFor($user) : []) ?: [Panels::OWNER]);
    }

    public function home(): string
    {
        return Filament::getPanel($this->panels[0])->getUrl();
    }

    /**
     * @param  class-string  $class
     */
    private function panelOf(string $class): string
    {
        foreach ($this->panels as $id) {
            if (Panels::shows($class, $id)) {
                return $id;
            }
        }

        return $this->panels[0];
    }

    public function booking(Booking|int $booking): string
    {
        return BookingResource::getUrl('view', ['record' => $booking], panel: $this->panelOf(BookingResource::class));
    }

    public function reviews(): string
    {
        return ReviewResource::getUrl('index', panel: $this->panelOf(ReviewResource::class));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function inbox(array $parameters = []): string
    {
        return Inbox::getUrl($parameters, panel: $this->panelOf(Inbox::class));
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
