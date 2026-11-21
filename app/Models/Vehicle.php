<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's unit (year, make and model, type, plate), kept for repeat
 * bookings. The admin assigns its size class once, after seeing it; a unit
 * that was reviewed is priced instantly on its next booking.
 *
 * @property CarbonImmutable|null $size_reviewed_at
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    protected $fillable = ['guest_id', 'year', 'model', 'type', 'plate_number', 'vehicle_size_id', 'size_reviewed_at'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'size_reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle) {
            $vehicle->plate_number = Booking::normalizePlate((string) $vehicle->plate_number);
        });
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<VehicleSize, $this>
     */
    public function vehicleSize(): BelongsTo
    {
        return $this->belongsTo(VehicleSize::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isReviewed(): bool
    {
        return $this->size_reviewed_at !== null && $this->vehicle_size_id !== null;
    }

    public function typeLabel(): string
    {
        return Booking::VEHICLE_TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    /**
     * Shape used by GET /api/my-vehicles.
     *
     * @return array{id: int, year: int, model: string, type: string, plate: string, sizeReviewed: bool}
     */
    public function toCustomer(): array
    {
        return [
            'id' => $this->getKey(),
            'year' => (int) $this->year,
            'model' => $this->model,
            'type' => $this->type,
            'plate' => $this->plate_number,
            'sizeReviewed' => $this->isReviewed(),
        ];
    }
}
