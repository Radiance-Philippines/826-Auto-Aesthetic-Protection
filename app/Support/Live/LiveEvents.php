<?php

namespace App\Support\Live;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ReferralRedemption;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Where the live events come from: the model events that already fire when
 * the queue moves, a booking is made or changed, a message arrives, points
 * change, or a bell notification is saved. Each one publishes a few ids and
 * what kind of change it was (never names, phones or message text); the
 * screens then look the details up themselves, with their own permissions.
 */
final class LiveEvents
{
    /**
     * Fields that change without anyone needing to see it live.
     */
    private const QUIET = ['updated_at', 'read_at', 'reminded_at'];

    public static function listen(): void
    {
        Booking::created(fn (Booking $booking) => self::booking($booking, true));
        Booking::updated(fn (Booking $booking) => self::booking($booking, false));
        Conversation::saved(fn (Conversation $conversation) => self::conversation($conversation));
        ConversationMessage::created(fn (ConversationMessage $message) => self::message($message));
        ReferralRedemption::saved(fn (ReferralRedemption $redemption) => self::points($redemption->code_guest_id));
        RewardRedemption::created(fn (RewardRedemption $redemption) => self::points($redemption->guest_id));
        DatabaseNotification::created(fn (DatabaseNotification $notification) => self::notification($notification));
    }

    public static function booking(Booking $booking, bool $created): void
    {
        if (! $created && array_diff(array_keys($booking->getChanges()), self::QUIET) === []) {
            return;
        }

        $topics = [];

        if ($booking->branch_id) {
            $topics = [Topics::queue($booking->branch_id), Topics::bookings($booking->branch_id)];
        }

        if ($booking->guest_id) {
            $topics[] = Topics::customer($booking->guest_id);
        }

        FlowLive::publish($topics, $created ? 'booking.created' : 'booking.changed', [
            'booking' => $booking->id,
            'branch' => $booking->branch_id,
            'status' => $booking->status,
        ]);
    }

    public static function conversation(Conversation $conversation): void
    {
        FlowLive::publish([Topics::inbox($conversation->branch_id)], 'conversation.changed', [
            'conversation' => $conversation->id,
            'branch' => $conversation->branch_id,
        ]);
    }

    public static function message(ConversationMessage $message): void
    {
        $conversation = $message->conversation;

        if (! $conversation) {
            return;
        }

        FlowLive::publish([Topics::inbox($conversation->branch_id)], 'message.created', [
            'conversation' => $conversation->id,
            'message' => $message->id,
            'branch' => $conversation->branch_id,
            'direction' => $message->direction,
        ]);
    }

    public static function points(?int $guestId): void
    {
        if ($guestId) {
            FlowLive::publish([Topics::customer($guestId)], 'rewards.changed', ['guest' => $guestId]);
        }
    }

    public static function notification(DatabaseNotification $notification): void
    {
        if ($notification->notifiable_type === (new User)->getMorphClass() && $notification->notifiable_id) {
            FlowLive::publish([Topics::notify((int) $notification->notifiable_id)], 'notification.created', ['notification' => $notification->id]);
        }
    }
}
