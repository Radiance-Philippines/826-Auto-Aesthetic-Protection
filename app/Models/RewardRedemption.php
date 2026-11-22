<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Points a customer spent: for a discount on a booking (peso_value, set
 * from the owner's value per point) or an adjustment with a note. Earned
 * points are the referral redemptions' points_awarded.
 *
 * @property CarbonImmutable $created_at
 */
class RewardRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['guest_id', 'points', 'peso_value', 'booking_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'peso_value' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
