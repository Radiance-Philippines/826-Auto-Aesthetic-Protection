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
 * notes that never leave the system. Channel "internal": a branch team's
 * own thread (subject, any teammate replies), not tied to a customer and
 * never sent anywhere. Messages only come in through
 * App\Support\Inbox\Inbox (and HeadsUp for the second channel).
 */
class Conversation extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    public const MESSENGER = 'messenger';

    public const HEADSUP = 'headsup';

    public const INTERNAL = 'internal';

    protected $fillable = [
        'branch_id',
        'channel',
        'external_id',
        'customer_name',
        'avatar',
        'guest_id',
        'booking_id',
        'linked_booking_id',
        'last_message_preview',
        'last_message_at',
        'last_inbound_at',
        'unread_count',
        'status',
        'assigned_to',
        'last_sent_by',
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
            'last_sent_by' => 'integer',
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
     * Only on internal threads: the booking the subject mentions, if any.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function linkedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'linked_booking_id');
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

    /**
     * With a user: an internal thread whose latest post is that user's own
     * is not unread for them (the count is shared by the branch team).
     */
    public function scopeUnread(Builder $query, ?User $user = null): void
    {
        $query->where('conversations.unread_count', '>', 0)
            ->when($user, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('conversations.channel', '!=', self::INTERNAL)
                ->orWhereNull('conversations.last_sent_by')
                ->orWhere('conversations.last_sent_by', '!=', $user->getKey())));
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
        if (in_array($channel, [self::MESSENGER, self::HEADSUP, self::INTERNAL], true)) {
            $query->where('conversations.channel', $channel);
        }
    }

    /**
     * What this team member may see, in every channel: the threads of their
     * branches (owners: all), and the sample chats only while the demo
     * samples are on.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $ids = $user ? $user->scopedBranchIds() : [];

        if ($ids !== null) {
            $query->whereIn('conversations.branch_id', $ids);
        }

        if (! SampleInbox::active()) {
            $query->where('conversations.is_sample', false);
        }
    }

    public function isHeadsUp(): bool
    {
        return $this->channel === self::HEADSUP;
    }

    public function isInternal(): bool
    {
        return $this->channel === self::INTERNAL;
    }

    /**
     * Heads-up and internal threads: team notes that never leave the system.
     */
    public function isTeamOnly(): bool
    {
        return $this->isHeadsUp() || $this->isInternal();
    }

    /**
     * The booking this thread is about: the heads-up's, or the one an internal thread mentions.
     */
    public function aboutBooking(): ?Booking
    {
        return $this->booking ?? $this->linkedBooking;
    }

    /**
     * The unread count this user sees (their own post in an internal thread is not unread to them).
     */
    public function unreadFor(?User $user): int
    {
        return $this->isInternal() && $user && $this->last_sent_by === $user->getKey() ? 0 : $this->unread_count;
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function displayName(): string
    {
        return $this->customer_name ?: ($this->isInternal() ? 'Team thread' : 'Messenger customer');
    }
}
