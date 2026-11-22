<?php

namespace App\Models;

use App\Support\Inbox\SampleInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's Messenger chat with one branch's Facebook Page, as shown in
 * the admin inbox. Messages only come in through App\Support\Inbox\Inbox.
 */
class Conversation extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    protected $fillable = [
        'branch_id',
        'channel',
        'external_id',
        'customer_name',
        'avatar',
        'guest_id',
        'last_message_preview',
        'last_message_at',
        'last_inbound_at',
        'unread_count',
        'status',
        'assigned_to',
        'is_sample',
    ];

    protected $attributes = [
        'channel' => 'messenger',
        'status' => self::OPEN,
        'unread_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
            'is_sample' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function scopeUnread(Builder $query): void
    {
        $query->where('unread_count', '>', 0);
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /**
     * What this team member may see: the chats of their branches (owners:
     * all), and the sample chats only while the demo samples are on.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if (! $user?->isOwner()) {
            $query->whereIn('conversations.branch_id', $user?->branchIds() ?? []);
        }

        if (! SampleInbox::active()) {
            $query->where('conversations.is_sample', false);
        }
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function displayName(): string
    {
        return $this->customer_name ?: 'Messenger customer';
    }
}
