<?php

namespace App\Support\Meta;

use App\Models\Branch;
use App\Models\Conversation;
use App\Models\MetaSetting;
use App\Support\Inbox\InboundMessage;
use App\Support\Inbox\Inbox;
use Illuminate\Support\Carbon;

/**
 * Turns a verified Messenger webhook into inbox messages, after the 200 has
 * been sent. Each entry's id is the Page that got the message, which is how
 * the branch is found. Echoes (the Page's own messages) are kept as replies,
 * so answers given in Meta Business Suite show in the thread too; this
 * site's own replies are recognized by message ID. Delivery, read and
 * reaction events are ignored.
 *
 * @see https://developers.facebook.com/docs/messenger-platform/reference/webhook-events/messages
 * @see https://developers.facebook.com/docs/messenger-platform/reference/webhook-events/message-echoes
 */
class MessagingWebhookHandler
{
    /**
     * Shown as "via …" on replies made outside this site.
     */
    public const OUTSIDE_SENDER = 'Meta inbox';

    public function __construct(private Inbox $inbox) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'page' || ! MetaSetting::instance()->isReady()) {
            return;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $branch = is_array($entry) && filled($entry['id'] ?? null)
                ? Branch::where('facebook_page_id', (string) $entry['id'])->first()
                : null;

            // A Page of another branch-less account subscribed to the same app
            if (! $branch) {
                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (is_array($event) && is_array($event['message'] ?? null)) {
                    $this->message($branch, $event);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function message(Branch $branch, array $event): void
    {
        $message = $event['message'];

        if (blank($message['mid'] ?? null) || ($message['is_deleted'] ?? false) || ($message['is_unsupported'] ?? false)) {
            return;
        }

        $fromShop = (bool) ($message['is_echo'] ?? false);
        $customerId = (string) ($fromShop ? ($event['recipient']['id'] ?? '') : ($event['sender']['id'] ?? ''));

        if ($customerId === '') {
            return;
        }

        $profile = $this->profileOfNewCustomer($branch, $customerId);

        $this->inbox->record($branch, new InboundMessage(
            customerId: $customerId,
            messageId: (string) $message['mid'],
            sentAt: is_numeric($event['timestamp'] ?? null) ? Carbon::createFromTimestampMs((int) $event['timestamp'])->setTimezone(config('app.timezone')) : now(),
            body: isset($message['text']) ? (string) $message['text'] : null,
            attachments: self::attachments($message['attachments'] ?? []),
            fromShop: $fromShop,
            senderName: $fromShop ? self::OUTSIDE_SENDER : null,
            customerName: $profile['name'] ?? null,
            customerAvatarUrl: $profile['avatar'] ?? null,
        ));
    }

    /**
     * The customer's name and photo, looked up once per conversation.
     *
     * @return array{name: string|null, avatar: string|null}|null
     */
    private function profileOfNewCustomer(Branch $branch, string $customerId): ?array
    {
        $known = Conversation::where('branch_id', $branch->getKey())->where('external_id', $customerId)->whereNotNull('customer_name')->exists();

        if ($known || blank($branch->facebook_page_token)) {
            return null;
        }

        try {
            return MetaClient::forBranch($branch)->profile($customerId);
        } catch (MetaException) {
            // The name is a nicety; the message matters
            return null;
        }
    }

    /**
     * @return list<array{type: string, url: string|null, name: string|null}>
     */
    private static function attachments(mixed $attachments): array
    {
        return collect(is_array($attachments) ? $attachments : [])
            ->filter(fn ($attachment) => is_array($attachment))
            ->map(fn (array $attachment) => [
                'type' => isset($attachment['payload']['sticker_id']) ? 'sticker' : (string) ($attachment['type'] ?? 'file'),
                'url' => is_string($attachment['payload']['url'] ?? null) ? $attachment['payload']['url'] : null,
                'name' => is_string($attachment['payload']['title'] ?? null) ? $attachment['payload']['title'] : null,
            ])
            ->values()
            ->all();
    }
}
