<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Sign-in security, set by a super admin in Settings → Security: whether
 * every user must set up two-factor sign-in. One row, cached until the
 * next save; off until the owner turns it on.
 */
class SecuritySetting extends Model
{
    private const CACHE_KEY = 'app826.security-settings';

    protected $fillable = ['require_two_factor'];

    protected function casts(): array
    {
        return ['require_two_factor' => 'boolean'];
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
}
