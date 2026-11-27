<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Access;
use App\Support\CurrentBranch;
use App\Support\Panels;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Passkey;

#[Fillable(['name', 'email', 'password', 'role', 'permissions'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
            'is_owner' => 'boolean',
            'permissions' => 'array',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'has_email_authentication' => 'boolean',
        ];
    }

    protected $attributes = [
        'role' => Access::BRANCH_MANAGER,
    ];

    protected static function booted(): void
    {
        // The old owner flag follows the role, so nothing reading it goes stale
        static::saving(function (User $user): void {
            $user->is_owner = $user->role === Access::SUPER_ADMIN;
        });
    }

    /**
     * The sample team accounts DemoData makes (reserved example.com, never a super admin).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function sample(Builder $query): void
    {
        $query->where('email', 'like', 'sample-%@example.com')->where('role', '!=', Access::SUPER_ADMIN);
    }

    public function isSample(): bool
    {
        return ! $this->isOwner() && str_starts_with($this->email, 'sample-') && str_ends_with($this->email, '@example.com');
    }

    /**
     * @var list<int>|null
     */
    private ?array $branchIdCache = null;

    /**
     * Only 826's team has accounts (there is no public sign-up): owners
     * invite team members from Users. Strict per panel: a super admin opens
     * all four (/owner, /sales, /operations, /system), a sales admin /sales,
     * detailers and branch managers /operations. /admin is only the shared sign-in and old links, open to
     * anyone with a panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === Panels::GATEWAY ? $this->canAccessAnyPanel() : Panels::canOpen($this, $panel->getId());
    }

    public function canAccessAnyPanel(): bool
    {
        return Panels::idsFor($this) !== [];
    }

    /**
     * A super admin: every area, every branch.
     */
    public function isOwner(): bool
    {
        return $this->role === Access::SUPER_ADMIN;
    }

    /**
     * The areas this account works in: its own list when it has one, else its role's.
     *
     * @return list<string>
     */
    public function areas(): array
    {
        if ($this->isOwner()) {
            return Access::defaults(Access::SUPER_ADMIN);
        }

        return is_array($this->permissions)
            ? array_values(array_intersect(array_keys(Access::AREAS), $this->permissions))
            : Access::defaults($this->role);
    }

    /**
     * Not Laravel's can(): that is the Gate.
     */
    public function canAccessArea(string $area): bool
    {
        return in_array($area, $this->areas(), true);
    }

    /**
     * Any of these areas.
     */
    public function canAccessAnyArea(string ...$areas): bool
    {
        return array_intersect($areas, $this->areas()) !== [];
    }

    public function roleLabel(): string
    {
        return Access::ROLES[$this->role] ?? $this->role;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return array<string>|null
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function hasEmailAuthentication(): bool
    {
        return (bool) $this->has_email_authentication;
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $this->has_email_authentication = $condition;
        $this->save();
    }

    /**
     * Signed in with an app code or an email code.
     */
    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret) || (bool) $this->has_email_authentication;
    }

    /**
     * @return HasMany<Passkey, $this>
     */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class, 'authenticatable_id');
    }

    public function getPassKeyName(): string
    {
        return $this->email;
    }

    public function getPassKeyId(): string
    {
        return (string) $this->getKey();
    }

    public function getPassKeyDisplayName(): string
    {
        return $this->name;
    }

    /**
     * At least one passkey, which counts as strong sign-in when "two-factor
     * for everyone" is on (while passkey sign-in is allowed).
     */
    public function hasPasskeys(): bool
    {
        return $this->passkeys()->exists();
    }

    /**
     * For a locked-out user: clears both factors and the recovery codes.
     */
    public function resetTwoFactor(): void
    {
        $this->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'has_email_authentication' => false,
        ])->save();
    }

    /**
     * Times a super admin started viewing as this person.
     *
     * @return HasMany<SecurityEvent, $this>
     */
    public function impersonationEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class)->where('kind', SecurityEvent::IMPERSONATION_STARTED);
    }

    /**
     * The staff app's sign-ins, one per device.
     *
     * @return HasMany<UserToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(UserToken::class);
    }

    /**
     * The branches a staff member works at.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /**
     * @return list<int>
     */
    public function branchIds(): array
    {
        return $this->branchIdCache ??= $this->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The branches this person's lists and numbers cover: the one chosen in
     * the admin's selector, else all of theirs. Null means no limit.
     *
     * @return list<int>|null
     */
    public function scopedBranchIds(): ?array
    {
        $chosen = CurrentBranch::for($this);

        if ($chosen !== null) {
            return [$chosen];
        }

        return $this->isOwner() ? null : $this->branchIds();
    }

    public function canManageBranch(int|Branch|null $branch): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $id !== null && in_array((int) $id, $this->branchIds(), true);
    }

    public function forgetBranchIds(): void
    {
        $this->branchIdCache = null;
    }

    /**
     * Invited, but hasn't chosen a password from the link yet.
     */
    public function isPendingInvite(): bool
    {
        return $this->invited_at !== null && $this->email_verified_at === null;
    }
}
