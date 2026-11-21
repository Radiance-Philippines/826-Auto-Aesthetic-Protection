<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A job brought back under warranty, logged by the team against the
 * booking (and the service on it) it came from.
 *
 * @property CarbonImmutable $noted_on
 */
class Backjob extends Model
{
    protected $fillable = ['booking_id', 'booking_item_id', 'backjob_type_id', 'branch_id', 'noted_on', 'note', 'status', 'created_by'];

    protected function casts(): array
    {
        return [
            'noted_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<BookingItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class, 'booking_item_id');
    }

    /**
     * @return BelongsTo<BackjobType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(BackjobType::class, 'backjob_type_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
