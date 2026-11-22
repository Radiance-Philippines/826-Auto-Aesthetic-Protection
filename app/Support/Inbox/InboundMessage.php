<?php

namespace App\Support\Inbox;

use Carbon\CarbonInterface;

/**
 * One Messenger message as the webhook reported it, before it is stored.
 * Outbound ones (fromShop) are replies made outside this site, e.g. in Meta
 * Business Suite.
 */
final readonly class InboundMessage
{
    /**
     * @param  list<array{type: string, url: string|null, name: string|null}>  $attachments
     */
    public function __construct(
        public string $customerId,
        public string $messageId,
        public CarbonInterface $sentAt,
        public ?string $body = null,
        public array $attachments = [],
        public bool $fromShop = false,
        public ?string $senderName = null,
        public ?string $customerName = null,
        public ?string $customerAvatarUrl = null,
    ) {}
}
