<?php

namespace App\Support;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * When operations (or a branch manager) changes a booking's ready date or
 * its unit, the sales side of that booking's branch is told: a bell
 * notification in the admin for everyone there with the sales area, and one
 * email to the branch's alert address. Changes by anyone else say nothing.
 */
final class HeadsUp
{
    private const ACTORS = [Access::OPERATIONS, Access::BRANCH_MANAGER];

    /**
     * @param  string  $what  e.g. "the ready date" or "the unit"
     */
    public static function send(Booking $booking, string $what, ?string $from, ?string $to): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! in_array($actor->role, self::ACTORS, true)) {
            return;
        }

        $change = trim(($from ?: 'nothing').' → '.($to ?: 'nothing'));
        $text = "{$actor->name} changed {$what}: {$change}";
        $url = BookingResource::getUrl('view', ['record' => $booking], panel: 'admin');

        $sales = User::query()->get()->filter(fn (User $user) => $user->isNot($actor)
            && $user->canAccessArea('bookings.sales')
            && $user->canManageBranch($booking->branch_id));

        $bell = Notification::make()
            ->title("Heads-up: {$booking->name} ({$booking->reference})")
            ->body($text)
            ->icon('heroicon-o-bell-alert')
            ->warning()
            ->actions([Action::make('open')->label('Open booking')->url($url)->markAsRead()]);

        // Now, not queued: nothing here runs a queue worker
        $sales->each(fn (User $user) => $user->notifyNow($bell->toDatabase()));

        Notifier::team($booking, 'heads_up', $text);
    }
}
