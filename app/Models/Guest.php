<?php

namespace App\Models;

use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A customer. Most signed in with Google to reserve and follow their
 * bookings; walk-in and phone customers are added by the team and have no
 * Google account (google_id and email may be empty). Separate from
 * App\Models\User (the admin's team), with its own "guest" auth guard, so a
 * guest can never open /admin.
 */
class Guest extends Authenticatable
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    public const SOURCES = [
        'google' => 'Signed in with Google',
        'walk_in' => 'Walk-in',
        'phone' => 'Call or message',
        'admin' => 'Added in the admin',
    ];

    protected $fillable = [
        'google_id',
        'name',
        'email',
        'avatar_url',
        'phone',
        'last_signed_in_at',
        'branch_id',
        'sales_rep_id',
        'referral_code',
        'notes',
        'source',
    ];

    protected $hidden = [
        'google_id',
        'remember_token',
    ];

    protected $attributes = [
        'source' => 'google',
    ];

    protected function casts(): array
    {
        return [
            'last_signed_in_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Guest $guest) {
            $guest->phone_key = self::phoneKey($guest->phone);
        });
    }

    /**
     * The last 10 digits of a phone number, so 0917 123 4567 and
     * +63 917 123 4567 are the same customer; null when it is too short.
     */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /**
     * The customer with this phone number, or a new walk-in customer.
     * Their home branch is set from their first booking.
     */
    public static function findOrCreateByPhone(string $name, string $phone, ?int $branchId = null, ?string $email = null, string $source = 'walk_in'): self
    {
        $key = self::phoneKey($phone);
        $guest = $key ? static::query()->where('phone_key', $key)->oldest('id')->first() : null;

        if ($guest) {
            $guest->fill(array_filter([
                'branch_id' => $guest->branch_id ?? $branchId,
                'email' => $guest->email ?? $email,
            ]));

            return tap($guest)->save();
        }

        return static::create([
            'name' => $name,
            'phone' => $phone,
            'email' => filled($email) ? $email : null,
            'branch_id' => $branchId,
            'source' => $source,
        ]);
    }

    /**
     * Customers the team member may work with: the owner sees everyone;
     * staff see those who booked at one of their branches or whose home
     * branch is theirs.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if ($user?->isOwner()) {
            return;
        }

        $ids = $user?->branchIds() ?? [];

        $query->where(fn (Builder $scope) => $scope
            ->whereIn('guests.branch_id', $ids)
            ->orWhereHas('bookings', fn (Builder $bookings) => $bookings->whereIn('bookings.branch_id', $ids)));
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    /**
     * Bookings that used this customer's referral code.
     *
     * @return HasMany<ReferralRedemption, $this>
     */
    public function referralRedemptions(): HasMany
    {
        return $this->hasMany(ReferralRedemption::class, 'code_guest_id');
    }

    public function isSignedUpWithGoogle(): bool
    {
        return filled($this->google_id);
    }

    /**
     * Shape used by the React site (window.APP826.auth.guest).
     *
     * @return array{name: string, email: string|null, avatar: string|null}
     */
    public function toSite(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
        ];
    }
}
