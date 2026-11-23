<?php

namespace App\Support\Inbox;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MetaSetting;
use App\Models\User;
use App\Support\AdminUrl;
use App\Support\InboxAccess;
use App\Support\Meta\MetaClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The admin inbox: every branch's Messenger chats in one place. The webhook
 * hands messages to record(), which stores each one once (by Meta's message
 * ID), so a repeated or late delivery is harmless.
 *
 * Replies to Messenger have no limit of ours (same as the WZGD inbox): Meta
 * itself refuses a reply outside its 24-hour window and that error is shown.
 */
class Inbox
{
    /**
     * One bell notification per conversation in this window, however many
     * messages the customer sends.
     */
    private const NOTIFY_EVERY_MINUTES = 10;

    /**
     * Older messages don't ring the bell.
     */
    private const NOTIFY_MAX_AGE_HOURS = 24;

    /**
     * Meta's standard messaging window: a hint in the composer, not a block.
     */
    public const WINDOW_HOURS = 24;

    /**
     * Nothing goes to Meta: on a demo (APP_DEMO) site, on a sample chat and
     * always on a heads-up or internal thread (their replies are internal
     * notes, kept in the thread only). reply() and markRead() both go through
     * this, so Meta can never be called for either.
     */
    public static function sendsNothing(Conversation $conversation): bool
    {
        return (bool) config('app.demo') || $conversation->is_sample || $conversation->isTeamOnly();
    }

    /**
     * Store a message from the webhook. Returns null when it was already stored.
     */
    public function record(Branch $branch, InboundMessage $message): ?ConversationMessage
    {
        $conversation = Conversation::createOrFirst(
            ['branch_id' => $branch->getKey(), 'channel' => 'messenger', 'external_id' => $message->customerId],
        );

        $conversation->fill(array_filter([
            'customer_name' => $message->customerName !== null ? Str::limit(Str::squish($message->customerName), 250, '') : null,
            'avatar' => $message->customerAvatarUrl !== null && str_starts_with($message->customerAvatarUrl, 'https://') ? $message->customerAvatarUrl : null,
        ], fn ($value) => filled($value)));

        $stored = ConversationMessage::createOrFirst(
            ['external_id' => $message->messageId],
            [
                'conversation_id' => $conversation->getKey(),
                'direction' => $message->fromShop ? ConversationMessage::OUT : ConversationMessage::IN,
                'body' => filled($message->body) ? $message->body : null,
                'attachments' => $message->attachments ?: null,
                'sender_name' => $message->fromShop && filled($message->senderName) ? Str::limit($message->senderName, 250) : null,
                'sent_at' => $message->sentAt,
            ],
        );

        if (! $stored->wasRecentlyCreated) {
            if ($conversation->isDirty()) {
                $conversation->save();
            }

            return null;
        }

        $isLatest = $conversation->last_message_at === null || $stored->sent_at->gte($conversation->last_message_at);

        if ($isLatest) {
            $conversation->last_message_preview = self::preview($stored);
            $conversation->last_message_at = $stored->sent_at;
        }

        if ($stored->isInbound()) {
            if ($conversation->last_inbound_at === null || $stored->sent_at->gt($conversation->last_inbound_at)) {
                $conversation->last_inbound_at = $stored->sent_at;
            }

            $conversation->unread_count++;
            // The customer wrote again: back on the to-do list
            $conversation->status = Conversation::OPEN;
        } elseif ($isLatest) {
            // Answered somewhere else (Business Suite)
            $conversation->unread_count = 0;
        }

        $conversation->save();

        if ($stored->isInbound()
            && $stored->sent_at->gt(now()->subHours(self::NOTIFY_MAX_AGE_HOURS))
            && Cache::add("inbox-notified:{$conversation->getKey()}", true, now()->addMinutes(self::NOTIFY_EVERY_MINUTES))) {
            $this->notifyStaff($conversation, $stored);
        }

        return $stored;
    }

    /**
     * Send a reply from /admin. A failed send is kept in the thread with the
     * reason, then rethrown for the page to show. In a demo (or on a sample
     * chat) nothing is sent: the reply is only kept here.
     *
     * @throws InboxException
     */
    public function reply(Conversation $conversation, string $text, ?User $by = null): ConversationMessage
    {
        $text = trim($text);

        if ($text === '') {
            throw new InboxException('Write a message first.');
        }

        if ($conversation->isInternal()) {
            return $this->post($conversation, $text, $by);
        }

        $localOnly = self::sendsNothing($conversation);
        $branch = $conversation->branch;

        if (! $localOnly && (! MetaSetting::instance()->isReady() || ! $branch?->hasFacebookPage())) {
            throw new InboxException("Messenger isn't connected for {$branch?->name}. The owner sets it up in Settings → Meta and Branches → Facebook page.");
        }

        $attributes = [
            'conversation_id' => $conversation->getKey(),
            'direction' => ConversationMessage::OUT,
            'body' => $text,
            'sent_by' => $by?->getKey(),
            'sent_at' => now(),
        ];

        if ($localOnly) {
            $message = ConversationMessage::create($attributes);
        } else {
            try {
                $externalId = MetaClient::forBranch($branch)->sendMessage((string) $branch->facebook_page_id, $conversation->external_id, $text);
            } catch (InboxException $exception) {
                ConversationMessage::create([...$attributes, 'failed' => true, 'error' => $exception->getMessage()]);

                throw $exception;
            }

            // The echo webhook for this reply may already have stored it
            $message = ConversationMessage::createOrFirst(['external_id' => $externalId], $attributes);

            if (! $message->wasRecentlyCreated && $message->sent_by === null) {
                $message->forceFill(['sent_by' => $by?->getKey(), 'sender_name' => null])->save();
            }
        }

        $conversation->forceFill([
            'last_message_preview' => self::preview($message),
            'last_message_at' => $message->sent_at,
            'unread_count' => 0,
        ])->save();

        if ($conversation->isHeadsUp()) {
            $this->notifyChangeMaker($conversation, $message, $by);
        }

        return $message;
    }

    /**
     * A team member starts an internal thread in one of their branches: a
     * subject and a first message, optionally about a booking (by reference,
     * at that branch). Nothing here is ever sent outside the system.
     *
     * @throws InboxException
     */
    public function startInternal(User $by, int $branchId, string $subject, string $text, ?string $bookingReference = null): Conversation
    {
        if (! InboxAccess::allows($by) || ! $by->canManageBranch($branchId) || ! Branch::query()->whereKey($branchId)->exists()) {
            throw new InboxException('Pick one of your branches.');
        }

        $subject = Str::limit(Str::squish($subject), 120, '');

        if ($subject === '' || trim($text) === '') {
            throw new InboxException('Write a subject and a message first.');
        }

        $reference = trim((string) $bookingReference);
        $booking = $reference === '' ? null : Booking::query()->where('branch_id', $branchId)->where('reference', $reference)->first();

        if ($reference !== '' && ! $booking) {
            throw new InboxException("There is no booking {$reference} at this branch.");
        }

        $conversation = Conversation::create([
            'branch_id' => $branchId,
            'channel' => Conversation::INTERNAL,
            'external_id' => 'internal-'.Str::ulid(),
            'customer_name' => $subject,
            'linked_booking_id' => $booking?->getKey(),
        ]);

        $this->post($conversation, $text, $by);

        return $conversation->refresh();
    }

    /**
     * One post in an internal thread: any teammate's, shown by name. It
     * reopens a done thread and is unread for everyone but its author.
     */
    private function post(Conversation $conversation, string $text, ?User $by): ConversationMessage
    {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->getKey(),
            'direction' => ConversationMessage::IN,
            'body' => $text,
            'sent_by' => $by?->getKey(),
            'sent_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_preview' => Str::limit(($by?->name ?? 'Team').': '.Str::squish($text), 250, ''),
            'last_message_at' => $message->sent_at,
            'last_sent_by' => $by?->getKey(),
            'unread_count' => $conversation->unread_count + 1,
            'status' => Conversation::OPEN,
        ])->save();

        $this->notifyTeammates($conversation, $message, $by);

        return $message;
    }

    /**
     * The branch's other inbox users get a bell: one per author and thread
     * in a few minutes, however many posts. Never the author, never anyone
     * outside the branch (a super admin only when assigned to it).
     */
    private function notifyTeammates(Conversation $conversation, ConversationMessage $message, ?User $by): void
    {
        if (! Schema::hasTable('notifications') || ! Cache::add("inbox-internal-notified:{$conversation->getKey()}:{$by?->getKey()}", true, now()->addMinutes(self::NOTIFY_EVERY_MINUTES))) {
            return;
        }

        $first = $conversation->messages()->count() === 1;

        User::query()
            ->whereHas('branches', fn ($branches) => $branches->whereKey($conversation->branch_id))
            ->when($by, fn ($query) => $query->whereKeyNot($by->getKey()))
            ->get()
            ->filter(fn (User $user) => InboxAccess::allows($user))
            // Now, not queued: nothing here runs a queue worker
            ->each(fn (User $user) => $user->notifyNow(Notification::make()
                ->title(($first ? 'New team thread: ' : 'Team reply: ').$conversation->displayName())
                ->body(Str::limit(($by?->name ?? 'Team').': '.Str::squish((string) $message->body), 160).' · '.$conversation->branch?->name)
                ->icon('heroicon-o-chat-bubble-left-right')
                ->actions([Action::make('open')->label('Open thread')->url(AdminUrl::for($user)->inbox(['conversation' => $conversation->getKey()]))->markAsRead()])
                ->toDatabase()));
    }

    /**
     * Sales answered a heads-up: the team member who made the latest change
     * gets a bell notification (never the one answering).
     */
    private function notifyChangeMaker(Conversation $conversation, ConversationMessage $reply, ?User $by): void
    {
        $maker = $conversation->messages()->where('direction', ConversationMessage::IN)->whereNotNull('sent_by')->latest('sent_at')->latest('id')->first()?->sender;

        if (! $maker || $maker->is($by) || ! $maker->canManageBranch($conversation->branch_id)) {
            return;
        }

        $booking = $conversation->booking;

        // Now, not queued: nothing here runs a queue worker
        $maker->notifyNow(Notification::make()
            ->title('Sales replied: '.$conversation->displayName().($booking ? " ({$booking->reference})" : ''))
            ->body(Str::limit(($by?->name ?? 'Sales').': '.Str::squish((string) $reply->body), 160))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->actions(array_filter([
                InboxAccess::allows($maker) ? Action::make('open')->label('Open thread')->url(AdminUrl::for($maker)->inbox(['conversation' => $conversation->getKey()]))->markAsRead() : null,
            ]))
            ->toDatabase());
    }

    /**
     * A team member opened the conversation. Meta is told after the response.
     * An internal thread's author opening their own latest post clears nothing.
     */
    public function markRead(Conversation $conversation, ?User $by = null): void
    {
        if ($conversation->unread_count === 0 || $conversation->unreadFor($by) === 0) {
            return;
        }

        $conversation->forceFill(['unread_count' => 0])->save();

        $branch = $conversation->branch;

        if (! self::sendsNothing($conversation) && MetaSetting::instance()->isReady() && $branch?->hasFacebookPage()) {
            defer(function () use ($conversation, $branch) {
                try {
                    MetaClient::forBranch($branch)->markSeen((string) $branch->facebook_page_id, $conversation->external_id);
                } catch (InboxException) {
                    // Read receipts are a courtesy
                }
            });
        }
    }

    /**
     * Tell the branch's staff (and the owners) who may open the inbox.
     * Needs the panel's notification bell (the notifications table); without
     * it nothing is sent.
     */
    private function notifyStaff(Conversation $conversation, ConversationMessage $message): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $recipients = User::query()
            ->where(fn ($query) => $query->where('is_owner', true)->orWhereHas('branches', fn ($branches) => $branches->whereKey($conversation->branch_id)))
            ->get()
            ->filter(fn (User $user) => InboxAccess::allows($user));

        if ($recipients->isEmpty()) {
            return;
        }

        // One each: the link opens in that person's own panel
        $recipients->each(fn (User $user) => Notification::make()
            ->title('New message: '.$conversation->displayName())
            ->body(Str::limit(self::preview($message), 120).' · '.$conversation->branch?->name)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->actions([
                Action::make('open')
                    ->label('Open chat')
                    ->icon('heroicon-m-chat-bubble-left-right')
                    ->url(AdminUrl::for($user)->inbox(['conversation' => $conversation->getKey()]))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user));
    }

    /**
     * The line under the customer's name in the conversation list.
     */
    public static function preview(ConversationMessage $message): string
    {
        $text = Str::squish((string) $message->body);

        if ($text === '') {
            $type = $message->attachmentList()[0]['type'] ?? null;
            $text = match ($type) {
                'image' => 'Photo',
                'video' => 'Video',
                'audio' => 'Voice message',
                'sticker' => 'Sticker',
                null => 'Message',
                default => 'Attachment',
            };
        }

        return Str::limit(($message->isInbound() ? '' : 'You: ').$text, 250);
    }
}
