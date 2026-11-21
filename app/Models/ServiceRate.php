<?php

namespace App\Models;

use Database\Factories\ServiceRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price of one service for one size class. Never shown on the site:
 * it prices a booking once the admin assigns the unit its size class.
 */
class ServiceRate extends Model
{
    /** @use HasFactory<ServiceRateFactory> */
    use HasFactory;

    protected $fillable = ['service_id', 'vehicle_size_id', 'price'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<VehicleSize, $this>
     */
    public function vehicleSize(): BelongsTo
    {
        return $this->belongsTo(VehicleSize::class);
    }

    public function hasPrice(): bool
    {
        return $this->price !== null;
    }
}
