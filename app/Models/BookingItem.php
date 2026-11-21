<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service on a booking, as it was named and estimated when the booking
 * was made (its price is filled in when the admin reviews the unit).
 */
class BookingItem extends Model
{
    protected $fillable = ['booking_id', 'service_id', 'service_name', 'category_name', 'price', 'duration_days', 'warranty_months', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_days' => 'integer',
            'warranty_months' => 'integer',
            'sort_order' => 'integer',
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Prices are not here: the customer sees them on the booking's price,
     * once the admin has reviewed the unit.
     *
     * @return array{name: string, category: string|null, estimateDays: int|null}
     */
    public function toCustomer(): array
    {
        return [
            'name' => $this->service_name,
            'category' => $this->category_name,
            'estimateDays' => $this->duration_days ?: null,
        ];
    }
}
