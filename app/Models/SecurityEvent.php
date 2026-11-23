<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A small audit trail for sign-in security: passkeys added, removed or
 * used to sign in. Written when it happens, never edited.
 */
class SecurityEvent extends Model
{
    public const PASSKEY_ADDED = 'passkey_added';

    public const PASSKEY_REMOVED = 'passkey_removed';

    public const PASSKEY_SIGN_IN = 'passkey_sign_in';

    public const PASSKEYS_CLEARED = 'passkeys_cleared';

    public const GUEST_ACCOUNT_DELETED = 'guest_account_deleted';

    public const CUSTOMER_SIGN_IN_FAILED = 'customer_sign_in_failed';

    public const CUSTOMER_LOCKED_OUT = 'customer_locked_out';

    public const CUSTOMER_PASSWORD_RESET = 'customer_password_reset';

    public const STAFF_APP_SIGN_IN = 'staff_app_sign_in';

    public const STAFF_APP_SIGN_IN_FAILED = 'staff_app_sign_in_failed';

    public const STAFF_APP_SIGN_OUT = 'staff_app_sign_out';

    public const LABELS = [
        self::PASSKEY_ADDED => 'Passkey added',
        self::PASSKEY_REMOVED => 'Passkey removed',
        self::PASSKEY_SIGN_IN => 'Signed in with a passkey',
        self::PASSKEYS_CLEARED => 'Passkeys removed by a super admin',
        self::GUEST_ACCOUNT_DELETED => 'Customer deleted their account in the app',
        self::CUSTOMER_SIGN_IN_FAILED => 'Failed customer sign-in (wrong password)',
        self::CUSTOMER_LOCKED_OUT => 'Customer sign-in locked after too many wrong passwords',
        self::CUSTOMER_PASSWORD_RESET => 'Customer chose a new password from an emailed link',
        self::STAFF_APP_SIGN_IN => 'Signed in to the team app',
        self::STAFF_APP_SIGN_IN_FAILED => 'Failed sign-in to the team app',
        self::STAFF_APP_SIGN_OUT => 'Signed out of the team app',
    ];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Writes the row with the current request's address and browser.
     */
    public static function record(string $kind, User $user, ?string $note = null, ?User $actor = null): self
    {
        return static::create([
            'user_id' => $user->getKey(),
            'actor_id' => $actor?->getKey(),
            'kind' => $kind,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }

    /**
     * Writes a row about a customer (no admin user behind it). Keep the note
     * free of their personal details: the account is being erased.
     */
    public static function recordForGuest(string $kind, Guest $guest, ?string $note = null): self
    {
        return static::create([
            'guest_id' => $guest->getKey(),
            'kind' => $kind,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
