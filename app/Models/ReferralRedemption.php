<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One booking that used a customer's referral code. The discount is blank
 * until the booking is priced; the code owner's points are awarded once,
 * when the booking exits; a cancelled, declined or expired booking voids it.
 *
 * @property CarbonImmutable|null $awarded_at
 * @property CarbonImmutable|null $voided_at
 */
class ReferralRedemption extends Model
{
    protected $fillable = ['code_guest_id', 'used_by_guest_id', 'booking_id', 'discount_amount', 'points_awarded', 'awarded_at', 'voided_at'];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'points_awarded' => 'integer',
            'awarded_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function codeOwner(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'code_guest_id');
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'used_by_guest_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * pending, awarded or voided.
     */
    public function status(): string
    {
        return $this->voided_at ? 'voided' : ($this->awarded_at ? 'awarded' : 'pending');
    }
}
