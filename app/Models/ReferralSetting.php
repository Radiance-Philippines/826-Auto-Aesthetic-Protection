<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Referral codes, set by the owner in Settings → Referral: on or off, the
 * discount a customer gets for using a friend's code (pesos off or percent
 * off) and the points the code's owner earns. One row, cached until the
 * next save. Nothing is filled in until the owner decides.
 */
class ReferralSetting extends Model
{
    private const CACHE_KEY = 'app826.referral-settings';

    public const TYPES = [
        'fixed' => 'Pesos off',
        'percent' => 'Percent off',
    ];

    protected $fillable = ['enabled', 'discount_type', 'discount_value', 'owner_points'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'discount_value' => 'integer',
            'owner_points' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function instance(): self
    {
        return static::query()->oldest('id')->first() ?? static::create()->refresh();
    }

    public static function current(): self
    {
        $attributes = Cache::rememberForever(self::CACHE_KEY, fn () => static::instance()->getAttributes());

        return (new static)->newFromBuilder($attributes);
    }

    public function hasDiscount(): bool
    {
        return $this->discount_value !== null && $this->discount_value > 0;
    }

    /**
     * Codes are accepted: switched on and a discount is set.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->hasDiscount();
    }

    /**
     * "₱500 off" or "10% off"; null while no discount is set.
     */
    public function label(): ?string
    {
        if (! $this->hasDiscount()) {
            return null;
        }

        return $this->discount_type === 'percent'
            ? "{$this->discount_value}% off"
            : '₱'.number_format($this->discount_value).' off';
    }

    /**
     * What a subtotal loses to the discount: never more than the subtotal.
     */
    public function discountFor(int $subtotal): int
    {
        if (! $this->hasDiscount() || $subtotal <= 0) {
            return 0;
        }

        $discount = $this->discount_type === 'percent'
            ? (int) round($subtotal * min(100, $this->discount_value) / 100)
            : $this->discount_value;

        return min($subtotal, $discount);
    }

    /**
     * @return array{enabled: bool, discountLabel: string|null}
     */
    public function toSite(): array
    {
        return ['enabled' => $this->isActive(), 'discountLabel' => $this->label()];
    }
}
