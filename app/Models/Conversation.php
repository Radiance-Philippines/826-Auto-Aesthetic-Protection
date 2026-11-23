<?php

namespace App\Models;

use App\Support\Inbox\SampleInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A thread in the admin inbox. Channel "messenger": a customer's chat with
 * one branch's Facebook Page. Channel "headsup": operations telling sales
 * about a booking's changed ready date or unit, answered with internal
 * notes that never leave the system. Messages only come in through
 * App\Support\Inbox\Inbox (and HeadsUp for the second channel).
 */
class Conversation extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    public const MESSENGER = 'messenger';

    public const HEADSUP = 'headsup';

    protected $fillable = [
        'branch_id',
        'channel',
        'external_id',
        'customer_name',
        'avatar',
        'guest_id',
        'booking_id',
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
     * Only on heads-up threads: the booking the change was made to.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
     * @param  Builder<Conversation>  $query
     */
    public function scopeOfChannel(Builder $query, string $channel): void
    {
        if (in_array($channel, [self::MESSENGER, self::HEADSUP], true)) {
            $query->where('conversations.channel', $channel);
        }
    }

    /**
     * What this team member may see, in both channels: the threads of their
     * branches (owners: all), and the sample chats only while the demo
     * samples are on.
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

    public function isHeadsUp(): bool
    {
        return $this->channel === self::HEADSUP;
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
