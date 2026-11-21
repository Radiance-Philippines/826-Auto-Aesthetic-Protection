<?php

namespace App\Models;

use App\Models\Concerns\HasPaymentAccounts;
use Illuminate\Database\Eloquent\Model;

/**
 * The reservation fee and the main GCash and Maya accounts, from
 * /admin → Payments. One row.
 *
 * With the fee off, a reservation is confirmed the moment it's made. With
 * it on (a fixed amount: no price exists at booking time), the day is held for hold_minutes while the customer pays in their
 * own app and uploads a screenshot; staff check it and mark it paid. Nothing
 * is charged on the site.
 */
class PaymentSetting extends Model
{
    use HasPaymentAccounts;

    public const METHODS = [
        'gcash' => 'GCash',
        'maya' => 'Maya',
    ];

    public const DISK = 'public';

    public const MIN_HOLD_MINUTES = 10;

    public const MAX_HOLD_MINUTES = 1440;

    protected $fillable = [
        'reservation_fee_enabled',
        'hold_minutes',
        'gcash_enabled',
        'gcash_qr',
        'gcash_name',
        'gcash_number',
        'maya_enabled',
        'maya_qr',
        'maya_name',
        'maya_number',
        'amount_rule',
        'amount_value',
        'instructions',
    ];

    protected $attributes = [
        'amount_rule' => 'fixed',
        'hold_minutes' => 30,
        'reservation_fee_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'reservation_fee_enabled' => 'boolean',
            'hold_minutes' => 'integer',
            'gcash_enabled' => 'boolean',
            'maya_enabled' => 'boolean',
            'amount_value' => 'integer',
        ];
    }

    /**
     * The editable row, created with its defaults if it is missing.
     */
    public static function instance(): self
    {
        return static::query()->oldest('id')->first() ?? static::create()->refresh();
    }

    public function feeEnabled(): bool
    {
        return (bool) $this->reservation_fee_enabled;
    }

    public function holdMinutes(): int
    {
        return max(self::MIN_HOLD_MINUTES, min(self::MAX_HOLD_MINUTES, (int) ($this->hold_minutes ?: 30)));
    }

    /**
     * The fixed reservation fee, 0 until the owner sets an amount.
     */
    public function feeAmount(): int
    {
        return max(0, (int) $this->amount_value);
    }

    /**
     * "₱500": for the site.
     */
    public function feeLabel(): ?string
    {
        return $this->feeEnabled() && $this->feeAmount() > 0 ? '₱'.number_format($this->feeAmount()) : null;
    }
}
