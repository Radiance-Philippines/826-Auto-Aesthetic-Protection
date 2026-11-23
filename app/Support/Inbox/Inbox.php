<?php

namespace App\Support\Inbox;

use App\Filament\Pages\Inbox as InboxPage;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MetaSetting;
use App\Models\User;
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
     * always on a heads-up thread (its replies are internal notes, kept in
     * the thread only). reply() and markRead() both go through this, so
     * Meta can never be called for a heads-up thread.
     */
    public static function sendsNothing(Conversation $conversation): bool
    {
        return (bool) config('app.demo') || $conversation->is_sample || $conversation->isHeadsUp();
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
                InboxAccess::allows($maker) ? Action::make('open')->label('Open thread')->url(InboxPage::getUrl(['conversation' => $conversation->getKey()], panel: 'admin'))->markAsRead() : null,
            ]))
            ->toDatabase());
    }

    /**
     * A team member opened the conversation. Meta is told after the response.
     */
    public function markRead(Conversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
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

        Notification::make()
            ->title('New message: '.$conversation->displayName())
            ->body(Str::limit(self::preview($message), 120).' · '.$conversation->branch?->name)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->actions([
                Action::make('open')
                    ->label('Open chat')
                    ->icon('heroicon-m-chat-bubble-left-right')
                    ->url(InboxPage::getUrl(['conversation' => $conversation->getKey()]))
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients);
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
