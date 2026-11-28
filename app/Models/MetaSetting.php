<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The one Meta app all branch Pages share (owner only, /admin → Meta). The
 * app secret and the webhook verify token are encrypted with APP_KEY and
 * never sent back to the browser; each branch keeps its own Page token.
 */
class MetaSetting extends Model
{
    protected $fillable = [
        'app_id',
        'app_secret',
        'enabled',
    ];

    protected $hidden = [
        'app_secret',
        'webhook_verify_token',
    ];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'verify_token_shown_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The editable row, created empty (with a fresh verify token) if missing.
     */
    public static function instance(): self
    {
        $settings = static::query()->oldest('id')->first() ?? static::create();

        if (blank($settings->webhook_verify_token)) {
            $settings->regenerateVerifyToken();
        }

        return $settings;
    }

    /**
     * A new token for Meta's webhook setup; returned so it can be shown once.
     */
    public function regenerateVerifyToken(): string
    {
        $token = Str::random(40);
        $this->forceFill(['webhook_verify_token' => $token, 'verify_token_shown_at' => null])->save();

        return $token;
    }

    /**
     * The token the first time it is looked at after being made, then never again.
     */
    public function revealVerifyTokenOnce(): ?string
    {
        if ($this->verify_token_shown_at !== null) {
            return null;
        }

        $token = (string) $this->webhook_verify_token;
        $this->markVerifyTokenShown();

        return $token;
    }

    public function markVerifyTokenShown(): void
    {
        $this->forceFill(['verify_token_shown_at' => now()])->save();
    }

    public function hasCredentials(): bool
    {
        return filled($this->app_id) && filled($this->app_secret);
    }

    /**
     * Messages are received and sent: the app is set up and switched on.
     */
    public function isReady(): bool
    {
        return $this->enabled && $this->hasCredentials();
    }
}
