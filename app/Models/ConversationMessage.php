<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a chat: from the customer (in) or the shop (out).
 */
class ConversationMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'conversation_id',
        'direction',
        'external_id',
        'body',
        'attachments',
        'sender_name',
        'sent_by',
        'sent_at',
        'failed',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
            'failed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::IN;
    }

    /**
     * @return list<array{type: string, url: string|null, name: string|null}>
     */
    public function attachmentList(): array
    {
        return array_values(array_filter((array) $this->attachments, 'is_array'));
    }
}
