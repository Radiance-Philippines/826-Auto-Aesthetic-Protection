<?php

namespace App\Models;

use App\Support\ImagePipeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo of a unit taken by the team in the staff app. The file is a clean
 * WebP master with renditions (ImagePipeline) under booking-photos/, and
 * goes with the booking when it is deleted. Only the booking's branch sees
 * it in the app.
 *
 * @property CarbonImmutable $created_at
 */
class BookingPhoto extends Model
{
    public const UPDATED_AT = null;

    public const MAX_PER_BOOKING = 30;

    protected $fillable = ['booking_id', 'user_id', 'path'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (BookingPhoto $photo) => app(ImagePipeline::class)->delete($photo->path));
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{id: int, url: string, thumb: string, by: string|null, at: string}
     */
    public function toStaff(): array
    {
        $pipeline = app(ImagePipeline::class);

        return [
            'id' => $this->getKey(),
            'url' => url($pipeline->url($this->path, 'lg')),
            'thumb' => url($pipeline->url($this->path, 'thumb')),
            'by' => $this->user?->name,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
