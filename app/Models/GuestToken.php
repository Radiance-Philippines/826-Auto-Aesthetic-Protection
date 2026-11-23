<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A customer app's sign-in on one device. The token itself (40 random
 * characters) is shown once, when it is issued; only its sha256 is kept.
 *
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 */
class GuestToken extends Model
{
    public const UPDATED_AT = null;

    /** A token stops working after this many days; the app signs in again. */
    public const LIFETIME_DAYS = 365;

    protected $fillable = ['guest_id', 'name', 'token_hash', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Makes a token for the guest and returns it in plain text (never stored).
     */
    public static function issue(Guest $guest, ?string $device): string
    {
        $guest->tokens()->where('expires_at', '<', now())->delete();

        $plain = Str::random(40);

        $guest->tokens()->create([
            'name' => filled($device) ? mb_substr(trim($device), 0, 120) : null,
            'token_hash' => self::hash($plain),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return $plain;
    }

    /**
     * The live row for a plain token, or null (unknown, revoked or expired).
     */
    public static function findLive(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', self::hash($plain))
            ->where(fn ($live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('guest')
            ->first();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
