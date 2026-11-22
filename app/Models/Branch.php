<?php

namespace App\Models;

use App\Models\Concerns\HasPaymentAccounts;
use App\Support\GoogleSignIn;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of 826's shops. Customers reserve a drop-off date here once the
 * branch is open and filled in (see missing()). It works one queue: the
 * daily cap is how many units it takes per day, and its weekly hours say
 * which days it is open (the times are only shown on the site).
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasPaymentAccounts;

    public const STATUSES = [
        'draft' => 'Draft (hidden)',
        'coming_soon' => 'Coming soon (listed, no booking)',
        'open' => 'Open for booking',
    ];

    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public const MAX_DAILY_CAP = 50;

    public const MAX_LEAD_DAYS = 60;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'sort_order',
        'city',
        'address',
        'map_url',
        'map_lat',
        'map_lng',
        'phone',
        'messenger_url',
        'facebook_url',
        'alert_email',
        'weekly_hours',
        'daily_cap',
        'lead_days',
        'months_ahead',
        'offers_home_service',
        'home_service_areas',
        'gcash_enabled',
        'gcash_qr',
        'gcash_name',
        'gcash_number',
        'maya_enabled',
        'maya_qr',
        'maya_name',
        'maya_number',
        'facebook_page_id',
        'facebook_page_name',
        'facebook_page_token',
        'facebook_connected_at',
    ];

    /**
     * The Page token never leaves the server (it is write-only in /admin).
     *
     * @var list<string>
     */
    protected $hidden = [
        'facebook_page_token',
    ];

    protected $attributes = [
        'status' => 'draft',
        'daily_cap' => 0,
        'lead_days' => 1,
        'months_ahead' => 3,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'map_lat' => 'float',
            'map_lng' => 'float',
            'weekly_hours' => 'array',
            'daily_cap' => 'integer',
            'lead_days' => 'integer',
            'months_ahead' => 'integer',
            'offers_home_service' => 'boolean',
            'home_service_areas' => 'array',
            'gcash_enabled' => 'boolean',
            'maya_enabled' => 'boolean',
            'facebook_page_token' => 'encrypted',
            'facebook_connected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A different Page or token has not been checked yet (Connect check sets the time again)
        static::saving(function (Branch $branch) {
            if ($branch->exists && $branch->isDirty(['facebook_page_id', 'facebook_page_token']) && ! $branch->isDirty('facebook_connected_at')) {
                $branch->facebook_connected_at = null;
            }
        });
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * On the site: open and coming-soon branches (drafts are hidden).
     */
    public function scopeListed(Builder $query): void
    {
        $query->whereIn('status', ['open', 'coming_soon']);
    }

    /**
     * The branches a team member may work with: all of them for owners.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if (! $user?->isOwner()) {
            $query->whereIn('id', $user?->branchIds() ?? []);
        }
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Messenger is on for this branch: its Page is checked and a token is saved.
     */
    public function hasFacebookPage(): bool
    {
        return filled($this->facebook_page_id) && filled($this->facebook_page_token) && $this->facebook_connected_at !== null;
    }

    /**
     * @return HasMany<BranchBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(BranchBlock::class);
    }

    /**
     * Each weekday's hours, checked: null when closed. Times are "HH:MM"
     * and only shown on the site; a weekday without hours is a closed day.
     *
     * @return array<int, array{open: string, close: string}|null>
     */
    public function weeklyHours(): array
    {
        $saved = is_array($this->weekly_hours) ? $this->weekly_hours : [];
        $hours = [];

        foreach (array_keys(self::WEEKDAYS) as $day) {
            $row = $saved[$day] ?? $saved[(string) $day] ?? null;
            $open = self::clock($row['open'] ?? null);
            $close = self::clock($row['close'] ?? null);

            $hours[$day] = ! is_array($row) || ($row['closed'] ?? false) || ! $open || ! $close || $open >= $close
                ? null
                : ['open' => $open, 'close' => $close];
        }

        return $hours;
    }

    /**
     * Whether the branch works on this weekday (closure blocks aside).
     */
    public function opensOn(CarbonImmutable $date): bool
    {
        return $this->weeklyHours()[$date->isoWeekday()] !== null;
    }

    public function hasHours(): bool
    {
        return array_filter($this->weeklyHours()) !== [];
    }

    /**
     * "08:00", "8:00", "08:00:00" → "08:00"; anything else → null.
     */
    public static function clock(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $match)) {
            return null;
        }

        [$hour, $minute] = [(int) $match[1], (int) $match[2]];

        return $hour < 24 && $minute < 60 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    public function dailyCap(): int
    {
        return max(0, min(255, (int) $this->daily_cap));
    }

    public function leadDays(): int
    {
        return max(0, min(self::MAX_LEAD_DAYS, (int) $this->lead_days));
    }

    public function monthsAhead(): int
    {
        return max(1, min(12, (int) ($this->months_ahead ?: 3)));
    }

    /**
     * The services on offer here that can be booked: active, with a day estimate.
     *
     * @return Collection<int, Service>
     */
    public function bookableServices(): Collection
    {
        return $this->services()->bookable()->get();
    }

    /**
     * What still stops customers from reserving here online. Empty when
     * the branch is ready (it still needs to be set to Open).
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->dailyCap() <= 0) {
            $missing[] = 'Set how many units the branch takes per day (daily cap).';
        }

        if (! $this->hasHours()) {
            $missing[] = 'Set the opening hours for at least one day.';
        }

        if ($this->bookableServices()->isEmpty()) {
            $missing[] = 'Offer at least one service that has a day estimate (Takes).';
        }

        $payments = PaymentSetting::instance();

        if ($payments->feeEnabled() && $payments->feeAmount() <= 0) {
            $missing[] = 'The reservation fee is on: set its amount in Payments.';
        }

        if ($payments->feeEnabled() && $this->paymentMethods() === []) {
            $missing[] = 'The reservation fee is on: add a GCash or Maya account here or in Payments.';
        }

        if (! GoogleSignIn::enabled() && ! config('app.demo') && ! app()->isLocal()) {
            $missing[] = 'Set up Google sign-in (Settings → Google sign-in): customers sign in to reserve.';
        }

        return $missing;
    }

    public function isOnsiteBookable(): bool
    {
        return $this->status === 'open' && $this->missing() === [];
    }

    public function takesHomeRequests(): bool
    {
        return $this->status === 'open' && $this->offers_home_service;
    }

    /**
     * Where customers pay the reservation fee: the branch's own accounts
     * when it has any, else the main ones (never a mix).
     *
     * @return list<array{key: string, label: string, qr: string|null, accountName: string|null, accountNumber: string|null}>
     */
    public function paymentMethods(): array
    {
        $own = $this->methods();

        return $own !== [] ? $own : PaymentSetting::instance()->methods();
    }

    /**
     * @return list<string>
     */
    public function homeServiceAreas(): array
    {
        return collect(is_array($this->home_service_areas) ? $this->home_service_areas : [])
            ->filter(fn ($area) => is_string($area) && filled($area))
            ->map(fn (string $area) => trim($area))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * "0948 804 2284" → "+639488042284", for tel: links.
     */
    public function phoneHref(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);

        return match (true) {
            $digits === '' => null,
            str_starts_with($digits, '63') => "+{$digits}",
            str_starts_with($digits, '0') => '+63'.substr($digits, 1),
            default => $digits,
        };
    }

    /**
     * Directions from the pin, or the owner's own Google Maps link.
     *
     * @return array{google: string|null, waze: string|null, embed: string|null}|null
     */
    public function map(): ?array
    {
        $hasPin = $this->map_lat !== null && $this->map_lng !== null;

        if (! $hasPin && blank($this->map_url)) {
            return null;
        }

        $pin = $hasPin ? "{$this->map_lat},{$this->map_lng}" : null;

        return [
            'google' => $this->map_url ?: ($pin ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($pin) : null),
            'waze' => $pin ? 'https://waze.com/ul?ll='.rawurlencode($pin).'&navigate=yes' : null,
            'embed' => $pin ? 'https://www.google.com/maps?q='.rawurlencode($pin).'&z=15&output=embed' : null,
        ];
    }

    /**
     * Shape used by the React site (window.APP826.branches).
     *
     * @return array<string, mixed>
     */
    public function toSite(): array
    {
        $hours = $this->weeklyHours();
        $serviceIds = $this->status === 'open' ? $this->bookableServices()->modelKeys() : [];

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status,
            'city' => $this->city,
            'address' => $this->address,
            'phone' => $this->phone,
            'phoneHref' => $this->phoneHref(),
            'messenger' => $this->messenger_url,
            'facebook' => $this->facebook_url,
            'map' => $this->map(),
            'hours' => collect(self::WEEKDAYS)->map(fn (string $label, int $day) => [
                'day' => $day,
                'label' => $label,
                'open' => $hours[$day]['open'] ?? null,
                'close' => $hours[$day]['close'] ?? null,
            ])->values()->all(),
            // The last day it takes bookings and home-service requests for
            'lastDay' => Availability::lastDay($this)->toDateString(),
            'bookable' => $this->isOnsiteBookable(),
            'serviceIds' => array_values(array_map('intval', $serviceIds)),
            'homeService' => [
                'offered' => $this->takesHomeRequests(),
                'areas' => $this->homeServiceAreas(),
            ],
        ];
    }
}
