<?php

namespace App\Support;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * When operations (or a branch manager) changes a booking's ready date or
 * its unit, the sales side of that booking's branch is told: a bell
 * notification in the admin for everyone there with the sales area, and one
 * email to the branch's alert address, and a thread in the admin Inbox
 * (channel "headsup", one per booking) that sales can open and answer with
 * internal notes. Changes by anyone else say nothing.
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

        self::addToInbox($booking, $actor, $text);
    }

    /**
     * One thread per booking: found or created, then the change appended and
     * the thread put back on the branch's to-do list as unread.
     */
    private static function addToInbox(Booking $booking, User $actor, string $text): void
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['booking_id' => $booking->getKey()],
            ['branch_id' => $booking->branch_id, 'channel' => Conversation::HEADSUP, 'external_id' => 'booking-'.$booking->getKey()],
        );

        $message = $conversation->messages()->create([
            'direction' => ConversationMessage::IN,
            'body' => "Heads-up: {$text}",
            'sent_by' => $actor->getKey(),
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'branch_id' => $booking->branch_id,
            'customer_name' => Str::limit($booking->vehicleLabel() ?: $booking->name, 250, ''),
            'guest_id' => $booking->guest_id,
            'last_message_preview' => Str::limit(Str::squish($message->body), 250),
            'last_message_at' => $message->sent_at,
            'unread_count' => $conversation->unread_count + 1,
            'status' => Conversation::OPEN,
        ])->save();
    }
}
