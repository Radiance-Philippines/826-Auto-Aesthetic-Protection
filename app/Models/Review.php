<?php

namespace App\Models;

use App\Models\Concerns\HasImageRenditions;
use App\Support\ImagePipeline;
use Carbon\CarbonImmutable;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A customer's review of a finished booking. Nothing shows on the site
 * until the team has read and approved it.
 *
 * @property CarbonImmutable|null $approved_at
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, HasImageRenditions;

    public const STATUSES = [
        'pending' => 'Waiting for approval',
        'approved' => 'Approved',
        'rejected' => 'Not shown',
    ];

    /**
     * Most reviews the site carries (window.APP826.reviews).
     */
    public const SITE_LIMIT = 24;

    protected $fillable = [
        'guest_id',
        'booking_id',
        'branch_id',
        'rating',
        'body',
        'photo',
        'status',
        'admin_note',
        'approved_at',
        'display_name',
        'is_featured',
    ];

    protected $attributes = [
        'status' => 'pending',
        'is_featured' => false,
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_featured' => 'boolean',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function imageAttributes(): array
    {
        return ['photo'];
    }

    protected static function booted(): void
    {
        static::creating(function (Review $review) {
            $review->display_name = $review->display_name ?: self::displayNameFor($review->guest?->name ?? $review->booking?->name ?? '');
            $review->branch_id ??= $review->booking?->branch_id;
        });

        static::saving(function (Review $review) {
            if ($review->isDirty('status')) {
                $review->approved_at = $review->status === 'approved' ? ($review->approved_at ?? now()) : null;
            }

            if ($review->status !== 'approved') {
                $review->is_featured = false;
            }
        });
    }

    /**
     * "Maria Santos" becomes "Maria S."
     */
    public static function displayNameFor(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return $parts[0] ?? 'A customer';
        }

        return $parts[0].' '.Str::upper(Str::substr(end($parts), 0, 1)).'.';
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /**
     * What the customer sees of their own review.
     *
     * @return array{rating: int, body: string, status: string, photo: string|null}
     */
    public function toCustomer(): array
    {
        return [
            'rating' => $this->rating,
            'body' => $this->body,
            'status' => $this->status,
            'photo' => $this->photo ? app(ImagePipeline::class)->url($this->photo, 'sm') : null,
        ];
    }

    /**
     * Shape used by the React site (window.APP826.reviews).
     *
     * @return array<string, mixed>
     */
    public function toSite(): array
    {
        $booking = $this->booking;
        $items = $booking?->relationLoaded('items') ? $booking->items : $booking?->items()->get();

        return [
            'id' => $this->id,
            'name' => $this->display_name,
            'rating' => $this->rating,
            'body' => $this->body,
            'photo' => $this->photo ? app(ImagePipeline::class)->urls($this->photo) : null,
            'service' => $items?->first()?->service_name,
            'branch' => $this->branch?->name,
            'date' => ($this->approved_at ?? $this->created_at)?->toDateString(),
        ];
    }

    /**
     * The approved reviews the site carries: featured first, then newest.
     *
     * @return list<array<string, mixed>>
     */
    public static function forSite(): array
    {
        return static::query()
            ->approved()
            ->with(['branch', 'booking.items'])
            ->orderByDesc('is_featured')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit(self::SITE_LIMIT)
            ->get()
            ->map->toSite()
            ->values()
            ->all();
    }
}
