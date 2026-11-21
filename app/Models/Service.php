<?php

namespace App\Models;

use App\Models\Concerns\HasImageRenditions;
use App\Support\ImagePipeline;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a customer can book (Graphene coating, Full body PPF, Nano
 * ceramic tint…): one estimate in days, and a price per vehicle size class
 * that is used once the admin has seen the unit. Offered at the branches
 * it's attached to.
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasImageRenditions;

    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'summary',
        'description',
        'inclusions',
        'image',
        'sort_order',
        'is_active',
        'home_service_ok',
        'estimate_days',
    ];

    protected function casts(): array
    {
        return [
            'inclusions' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'home_service_ok' => 'boolean',
            'estimate_days' => 'integer',
        ];
    }

    public function imageAttributes(): array
    {
        return ['image'];
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('category', fn (Builder $category) => $category->where('is_active', true));
    }

    /**
     * Active, in an active category, and with a day estimate: the owner
     * fills the estimate in, so a service without one can't be booked yet.
     */
    public function scopeBookable(Builder $query): void
    {
        $query->active()->where('estimate_days', '>', 0);
    }

    public function isBookable(): bool
    {
        return $this->is_active && $this->estimate_days > 0 && $this->category?->is_active !== false;
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * @return HasMany<ServiceRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ServiceRate::class);
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * The rate for a size, if one is set up.
     */
    public function rateFor(int $vehicleSizeId): ?ServiceRate
    {
        $rates = $this->relationLoaded('rates') ? $this->rates : $this->rates()->get();

        return $rates->firstWhere('vehicle_size_id', $vehicleSizeId);
    }

    /**
     * @return list<string>
     */
    public function inclusionList(): array
    {
        return collect(is_array($this->inclusions) ? $this->inclusions : [])
            ->map(fn ($item) => is_array($item) ? ($item['item'] ?? null) : $item)
            ->filter(fn ($item) => is_string($item) && filled($item))
            ->values()
            ->all();
    }

    /**
     * Shape used by the React site (window.APP826.catalog). No sizes, rates
     * or prices: the price comes after the admin has reviewed the unit.
     *
     * @return array<string, mixed>
     */
    public function toSite(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'description' => $this->description,
            'inclusions' => $this->inclusionList(),
            'image' => $this->image ? app(ImagePipeline::class)->urls($this->image) : null,
            'homeServiceOk' => $this->home_service_ok,
            'estimateDays' => $this->estimate_days > 0 ? (int) $this->estimate_days : null,
        ];
    }
}
