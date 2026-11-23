<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The staff app's sign-in on one device. The token itself (40 random
 * characters) is shown once, when it is issued; only its sha256 is kept.
 * It lasts 30 days from the last time it was used.
 *
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 */
class UserToken extends Model
{
    public const UPDATED_AT = null;

    /** A token that isn't used for this many days stops working. */
    public const LIFETIME_DAYS = 30;

    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Makes a token for the user and returns it in plain text (never stored).
     *
     * @return array{0: string, 1: self}
     */
    public static function issue(User $user, ?string $device): array
    {
        $user->tokens()->where('expires_at', '<', now())->delete();

        $plain = Str::random(40);

        $token = $user->tokens()->create([
            'name' => filled($device) ? mb_substr(trim($device), 0, 120) : null,
            'token_hash' => self::hash($plain),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$plain, $token];
    }

    /**
     * The live row for a plain token, or null (unknown, revoked or expired).
     */
    public static function findLive(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', self::hash($plain))
            ->where(fn ($live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('user')
            ->first();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Slides the expiry forward; at most once a minute, so a busy screen is not a write per request.
     */
    public function touchUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lessThan(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now(), 'expires_at' => now()->addDays(self::LIFETIME_DAYS)])->saveQuietly();
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
