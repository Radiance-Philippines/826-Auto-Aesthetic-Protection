<?php

namespace App\Support\Inbox;

use App\Models\Branch;
use App\Models\ConversationMessage;
use App\Models\MetaSetting;

/**
 * Made-up chats so the inbox can be shown before Meta is set up. Only on a
 * demo (APP_DEMO) or local site, and only while Meta isn't switched on; they
 * are marked is_sample, labelled "Sample" in the admin and never sent or
 * received through Meta.
 */
final class SampleInbox
{
    /**
     * [customer, [[direction, minutes ago, text]...], unread] per chat.
     *
     * @var list<array{0: string, 1: list<array{0: string, 1: int, 2: string}>, 2: int}>
     */
    private const CHATS = [
        ['Maria Santos', [['in', 190, 'Hi po! Pwede po ba magpa-ceramic coating ng SUV?'], ['out', 170, 'Hello po, Maria! Salamat po sa pag-message. Ano po ang model at taon ng unit?'], ['in', 12, 'Fortuner 2022 po. Ilang araw po kaya siya?']], 1],
        ['Jun dela Cruz', [['in', 1500, 'Good morning po, pwede po ba dito mag-inquire para sa booking?'], ['out', 1440, 'Good morning po! Opo, dito na po kayo mag-message, o mag-book po kayo sa website namin.']], 0],
        ['Angela Reyes', [['in', 45, 'Hello! Magkano po ang PPF para sa sedan?'], ['in', 44, 'Pati po kung may warranty']], 2],
    ];

    /**
     * Sample chats are on: a demo or local site, with Meta not set up.
     */
    public static function active(): bool
    {
        return (config('app.demo') || app()->isLocal()) && ! MetaSetting::instance()->isReady();
    }

    /**
     * Give each of the first three branches its sample chats, once.
     */
    public static function ensureSeeded(): void
    {
        if (! self::active()) {
            return;
        }

        foreach (Branch::query()->ordered()->limit(3)->get() as $branch) {
            if ($branch->conversations()->where('is_sample', true)->exists()) {
                continue;
            }

            foreach (self::CHATS as $index => [$name, $messages, $unread]) {
                $conversation = $branch->conversations()->create([
                    'external_id' => "sample-{$branch->getKey()}-{$index}",
                    'customer_name' => $name,
                    'unread_count' => $unread,
                    'is_sample' => true,
                ]);

                foreach ($messages as [$direction, $minutesAgo, $text]) {
                    $message = ConversationMessage::create([
                        'conversation_id' => $conversation->getKey(),
                        'direction' => $direction,
                        'body' => $text,
                        'sent_at' => now()->subMinutes($minutesAgo),
                    ]);

                    $conversation->forceFill([
                        'last_message_preview' => Inbox::preview($message),
                        'last_message_at' => $message->sent_at,
                        'last_inbound_at' => $direction === 'in' ? $message->sent_at : $conversation->last_inbound_at,
                    ]);
                }

                $conversation->save();
            }
        }
    }
}
