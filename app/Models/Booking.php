<?php

namespace App\Models;

use App\Support\Referrals;
use App\Support\Schedule\Estimate;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A reservation or a home-service request.
 *
 * Onsite reservations are made by customers on the site for a drop-off date
 * (never a time): the unit counts toward the branch's daily cap, and the
 * shop works through one queue per branch. The price is blank until the
 * admin has seen the unit and assigned its size class. With the reservation
 * fee on, the day is held until hold_expires_at for the customer to send a
 * payment screenshot; staff check it and the booking is confirmed.
 * Home-service requests don't count toward the cap: staff schedule them.
 *
 * @property CarbonImmutable|null $drop_off_on
 * @property CarbonImmutable|null $ready_on
 * @property CarbonImmutable|null $hold_expires_at
 * @property CarbonImmutable|null $size_reviewed_at
 * @property CarbonImmutable|null $priced_at
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public const KINDS = [
        'onsite' => 'At the branch',
        'home' => 'Home service',
    ];

    public const STATUSES = [
        'requested' => 'Home service request',
        'awaiting_payment' => 'Waiting for the reservation fee',
        'payment_review' => 'Screenshot to check',
        'confirmed' => 'Booked',
        'arrived' => 'Arrived',
        'in_progress' => 'In progress',
        'quality_check' => 'Quality check',
        'ready' => 'Ready',
        'exited' => 'Exited',
        'expired' => 'Hold expired',
        'cancelled' => 'Cancelled',
        'declined' => 'Declined',
        'no_show' => 'No-show',
    ];

    public const VEHICLE_TYPES = [
        'sedan' => 'Sedan',
        'hatchback' => 'Hatchback',
        'coupe' => 'Coupe',
        'suv' => 'SUV',
        'pickup' => 'Pickup',
        'van' => 'Van',
        'motorcycle' => 'Motorcycle',
    ];

    /**
     * Statuses that count toward a day's cap for an onsite booking. An
     * unpaid hold (awaiting_payment) counts only until hold_expires_at.
     */
    public const COUNTS_TOWARD_CAP = ['payment_review', 'confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'exited'];

    /**
     * The unit is in the shop: from arriving until it leaves.
     */
    public const IN_SHOP = ['arrived', 'in_progress', 'quality_check', 'ready'];

    /**
     * What happens next to an onsite unit, one step at a time.
     */
    public const NEXT_ONSITE = [
        'confirmed' => 'arrived',
        'arrived' => 'in_progress',
        'in_progress' => 'quality_check',
        'quality_check' => 'ready',
        'ready' => 'exited',
    ];

    /**
     * A home visit has no shop stages: booked, then in progress, then done.
     */
    public const NEXT_HOME = [
        'confirmed' => 'in_progress',
        'in_progress' => 'exited',
    ];

    /**
     * Not finished, cancelled or lapsed: still on the team's list.
     */
    public const OPEN = ['requested', 'awaiting_payment', 'payment_review', 'confirmed', 'arrived', 'in_progress', 'quality_check', 'ready'];

    public const WINDOWS = [
        'morning' => 'Morning',
        'afternoon' => 'Afternoon',
        'any' => 'Any time',
    ];

    public const SOURCES = [
        'site' => 'The site',
        'walk_in' => 'Walk-in',
        'phone' => 'Call or message',
        'admin' => 'Added in the admin',
        'manual' => 'Manual entry',
        'demo' => 'Sample data',
    ];

    /**
     * Payment screenshots stay off the public disk: only the customer
     * (signed in or with the booking's key) and the branch's team can open them.
     */
    public const PROOF_DISK = 'local';

    /**
     * Letters and digits that can't be misread over the phone.
     */
    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = [
        'kind',
        'source',
        'status',
        'branch_id',
        'guest_id',
        'vehicle_id',
        'drop_off_on',
        'ready_on',
        'preferred_date',
        'preferred_window',
        'vehicle_size_id',
        'vehicle_size_name',
        'referral_guest_id',
        'discount_amount',
        'amount_paid',
        'vehicle_year',
        'vehicle_model',
        'vehicle_type',
        'plate_number',
        'name',
        'phone',
        'email',
        'address',
        'area',
        'landmark',
        'has_water',
        'has_power',
        'has_shade',
        'message',
        'admin_note',
        'total_price',
        'total_days',
        'size_reviewed_at',
        'priced_at',
        'amount_due',
        'amount_kind',
        'hold_expires_at',
        'payment_method',
        'payment_proof',
        'payment_submitted_at',
        'payment_rejected_reason',
        'payment_note',
        'paid_at',
        'confirmed_at',
        'arrived_at',
        'ready_at',
        'exited_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'reschedule_count',
        'reminded_at',
        'read_at',
    ];

    /**
     * The private link's key opens the booking without signing in, so it is
     * never sent along with the booking itself.
     *
     * @var list<string>
     */
    protected $hidden = ['access_key', 'payment_proof'];

    protected $attributes = [
        'kind' => 'onsite',
        'source' => 'site',
        'total_days' => 0,
        'reschedule_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'drop_off_on' => 'immutable_date',
            'ready_on' => 'immutable_date',
            'preferred_date' => 'immutable_date',
            'vehicle_year' => 'integer',
            'size_reviewed_at' => 'immutable_datetime',
            'priced_at' => 'immutable_datetime',
            'has_water' => 'boolean',
            'has_power' => 'boolean',
            'has_shade' => 'boolean',
            'total_price' => 'integer',
            'discount_amount' => 'integer',
            'amount_paid' => 'integer',
            'total_days' => 'integer',
            'amount_due' => 'integer',
            'hold_expires_at' => 'immutable_datetime',
            'payment_submitted_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'exited_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'reschedule_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            $booking->access_key ??= Str::random(40);
            $booking->reference ??= self::newReference();
        });

        static::saving(function (Booking $booking) {
            // Dates are kept as "2026-11-24", so they compare as plain strings on SQLite too
            foreach (['drop_off_on', 'ready_on', 'preferred_date'] as $date) {
                if (is_string($booking->attributes[$date] ?? null)) {
                    $booking->attributes[$date] = substr($booking->attributes[$date], 0, 10);
                }
            }

            if ($booking->plate_number !== null) {
                $booking->plate_number = self::normalizePlate($booking->plate_number);
            }

            if ($booking->amount_due !== null) {
                $booking->amount_kind = 'fee';
            }

            if ($booking->isDirty('status')) {
                $now = now()->toImmutable();

                match ($booking->status) {
                    'confirmed' => $booking->confirmed_at ??= $now,
                    'arrived' => $booking->arrived_at ??= $now,
                    'ready' => $booking->ready_at ??= $now,
                    'exited' => $booking->exited_at ??= $now,
                    'cancelled', 'declined', 'expired' => $booking->cancelled_at ??= $now,
                    default => null,
                };
            }
        });

        // A referral code's points are earned when the booking exits, and lost when it falls through
        static::saved(function (Booking $booking) {
            if ($booking->wasChanged('status')) {
                Referrals::syncStatus($booking);
            }

            $booking->logChanges();
        });
    }

    /**
     * Writes the booking's history: status moves, ready-date and unit
     * changes, the size, and payment actions, with who did it. Not
     * backfilled, and nothing is written for the booking's creation.
     */
    private function logChanges(): void
    {
        if ($this->wasChanged('id')) {
            return;
        }

        $before = $this->getPrevious();
        $date = fn (mixed $value) => $value ? CarbonImmutable::parse($value)->format('D, M j') : null;
        $label = fn (?string $status) => $status ? (self::STATUSES[$status] ?? $status) : null;
        $unit = fn (array $source) => trim(implode(' ', array_filter([$source['vehicle_year'] ?? null, $source['vehicle_model'] ?? null, isset($source['vehicle_type']) ? (self::VEHICLE_TYPES[$source['vehicle_type']] ?? null) : null]))).(filled($source['plate_number'] ?? null) ? ' · '.$source['plate_number'] : '');

        $events = [];

        if ($this->wasChanged('status')) {
            $events[] = ['status', $label($before['status'] ?? null), $label($this->status), null];
        }

        if ($this->wasChanged('ready_on')) {
            $events[] = ['ready_on', $date($before['ready_on'] ?? null), $date($this->ready_on), null];
        }

        if ($this->wasChanged(['vehicle_year', 'vehicle_model', 'vehicle_type', 'plate_number'])) {
            $events[] = ['unit', $unit([...$this->only(['vehicle_year', 'vehicle_model', 'vehicle_type', 'plate_number']), ...$before]), $unit($this->only(['vehicle_year', 'vehicle_model', 'vehicle_type', 'plate_number'])), null];
        }

        if ($this->wasChanged('vehicle_size_name')) {
            $events[] = ['size', $before['vehicle_size_name'] ?? null, $this->vehicle_size_name, $this->total_price !== null ? 'Price ₱'.number_format($this->total_price) : null];
        }

        if ($this->wasChanged('paid_at') && $this->paid_at !== null) {
            $events[] = ['paid', null, null, $this->payment_note];
        }

        if ($this->wasChanged('payment_rejected_reason') && filled($this->payment_rejected_reason)) {
            $events[] = ['payment_rejected', null, null, $this->payment_rejected_reason];
        }

        if ($this->wasChanged('amount_paid')) {
            $events[] = ['payment', isset($before['amount_paid']) ? '₱'.number_format($before['amount_paid']) : null, $this->amount_paid !== null ? '₱'.number_format($this->amount_paid) : null, null];
        }

        if ($this->wasChanged('discount_amount') && $this->discount_amount !== null) {
            $events[] = ['discount', isset($before['discount_amount']) ? '₱'.number_format($before['discount_amount']) : null, '₱'.number_format($this->discount_amount), null];
        }

        foreach ($events as [$kind, $from, $to, $note]) {
            $this->events()->create(['user_id' => Auth::guard('web')->id(), 'kind' => $kind, 'from' => $from, 'to' => $to, 'note' => $note]);
        }
    }

    public static function newReference(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 5; $i++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }

            $reference = "826-{$code}";
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * "abc 1234" → "ABC1234".
     */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * The customer whose referral code this booking used.
     *
     * @return BelongsTo<Guest, $this>
     */
    public function referralGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'referral_guest_id');
    }

    /**
     * @return HasOne<ReferralRedemption, $this>
     */
    public function referralRedemption(): HasOne
    {
        return $this->hasOne(ReferralRedemption::class);
    }

    /**
     * @return BelongsTo<VehicleSize, $this>
     */
    public function vehicleSize(): BelongsTo
    {
        return $this->belongsTo(VehicleSize::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * The booking's history, newest first.
     *
     * @return HasMany<BookingEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->latest('id');
    }

    /**
     * @return HasMany<BookingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<Backjob, $this>
     */
    public function backjobs(): HasMany
    {
        return $this->hasMany(Backjob::class)->latest('noted_on')->latest('id');
    }

    /**
     * Onsite bookings that count toward their day's cap: booked or in the
     * shop, a screenshot waiting to be checked, or an unpaid hold that
     * hasn't lapsed (checked against the clock, so it frees up even
     * without cron).
     */
    public function scopeCountingTowardCap(Builder $query): void
    {
        $query->where('kind', 'onsite')
            ->whereNotNull('drop_off_on')
            ->where(fn (Builder $status) => $status
                ->whereIn('status', self::COUNTS_TOWARD_CAP)
                ->orWhere(fn (Builder $hold) => $hold
                    ->where('status', 'awaiting_payment')
                    ->where('hold_expires_at', '>', now())));
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /**
     * Open bookings whose unit the admin hasn't priced yet: no size class
     * assigned, or the class has no price for one of the services.
     */
    public function scopeNeedsSizeReview(Builder $query): void
    {
        $query->whereIn('status', self::OPEN)
            ->whereNull('priced_at')
            ->where(fn (Builder $hold) => $hold
                ->where('status', '!=', 'awaiting_payment')
                ->orWhereNull('hold_expires_at')
                ->orWhere('hold_expires_at', '>', now()));
    }

    /**
     * Something for the team to do: a screenshot to check, a home-service
     * request to schedule, or a unit that needs its size.
     */
    public function scopeNeedsAction(Builder $query): void
    {
        $query->where(fn (Builder $action) => $action
            ->whereIn('status', ['payment_review', 'requested'])
            ->orWhere(fn (Builder $review) => $review->needsSizeReview()));
    }

    /**
     * Unpaid holds whose time is up, for bookings:expire-holds.
     */
    public function scopeLapsedHolds(Builder $query): void
    {
        $query->where('status', 'awaiting_payment')
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now());
    }

    /**
     * Only the branches the team member works at (all of them for owners).
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if (! $user?->isOwner()) {
            $query->whereIn('branch_id', $user?->branchIds() ?? []);
        }
    }

    public function isOnsite(): bool
    {
        return $this->kind === 'onsite';
    }

    public function isHome(): bool
    {
        return $this->kind === 'home';
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->stage() !== 'expired';
    }

    /**
     * Where the booking is, as the customer sees it. An unpaid hold whose
     * time is up reads as expired before the command flips its status.
     */
    public function stage(): string
    {
        if ($this->status === 'awaiting_payment' && $this->hold_expires_at !== null && $this->hold_expires_at->lessThanOrEqualTo(now())) {
            return 'expired';
        }

        return $this->status;
    }

    public function isAwaitingPayment(): bool
    {
        return $this->stage() === 'awaiting_payment';
    }

    /**
     * Whether this booking counts toward its day's cap right now.
     */
    public function countsTowardCap(): bool
    {
        return $this->isOnsite()
            && $this->drop_off_on !== null
            && (in_array($this->status, self::COUNTS_TOWARD_CAP, true) || $this->stage() === 'awaiting_payment');
    }

    /**
     * The admin hasn't priced this unit yet.
     */
    public function needsSizeReview(): bool
    {
        return $this->isOpen() && $this->priced_at === null;
    }

    public function isInShop(): bool
    {
        return in_array($this->status, self::IN_SHOP, true);
    }

    /**
     * The status the next step moves it to, if it is on its way through the shop.
     */
    public function nextStatus(): ?string
    {
        return ($this->isHome() ? self::NEXT_HOME : self::NEXT_ONSITE)[$this->status] ?? null;
    }

    public function estimate(): Estimate
    {
        return new Estimate((int) $this->total_days);
    }

    /**
     * What's left to pay at the branch once the reservation fee is in.
     * Not shown until 826 says whether the fee counts toward the price.
     */
    public function balance(): ?int
    {
        return null;
    }

    /**
     * The customer's page, site-relative, with the private key so it opens
     * without signing in ("/bookings/12?key=…").
     */
    public function customerPath(): string
    {
        return "/bookings/{$this->getKey()}?key={$this->access_key}";
    }

    public function customerUrl(): string
    {
        return url($this->customerPath());
    }

    /**
     * Whether a request may open this booking: its signed-in customer, or the key.
     */
    public function isOpenedBy(?Guest $guest, ?string $key): bool
    {
        if ($guest && $this->guest_id !== null && $this->guest_id === $guest->getKey()) {
            return true;
        }

        return filled($key) && filled($this->access_key) && hash_equals($this->access_key, (string) $key);
    }

    /**
     * "Mon, Nov 24 · ready around Wed, Nov 26", or the home request's
     * preferred day.
     */
    public function whenLabel(): string
    {
        if ($this->drop_off_on) {
            return $this->drop_off_on->format('D, M j')
                .($this->ready_on ? ' · ready around '.$this->ready_on->format('D, M j') : '');
        }

        if ($this->preferred_date) {
            return 'Asked for '.$this->preferred_date->format('D, M j, Y').($this->preferred_window ? ' ('.strtolower(self::WINDOWS[$this->preferred_window] ?? $this->preferred_window).')' : '');
        }

        return '—';
    }

    /**
     * "Graphene coating, Nano ceramic tint".
     */
    public function servicesLabel(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->pluck('service_name')->implode(', ');
    }

    /**
     * "2021 Toyota Vios · Sedan · ABC1234".
     */
    public function vehicleLabel(): string
    {
        return collect([
            $this->vehicle_year,
            $this->vehicle_model,
            $this->vehicle_type ? '('.(self::VEHICLE_TYPES[$this->vehicle_type] ?? $this->vehicle_type).')' : null,
            $this->plate_number,
        ])
            ->filter()
            ->implode(' ');
    }

    /**
     * Whether the customer may still cancel it themselves.
     */
    public function canBeCancelledByCustomer(): bool
    {
        if (! $this->isOpen() || $this->isInShop()) {
            return false;
        }

        if ($this->drop_off_on === null || in_array($this->stage(), ['requested', 'awaiting_payment'], true)) {
            return true;
        }

        return now()->lessThanOrEqualTo($this->drop_off_on->startOfDay()->subHours(BookingSetting::current()->cancel_cutoff_hours));
    }

    /**
     * Only a finished booking's signed-in customer, once (a private link alone can't review).
     */
    public function canBeReviewed(): bool
    {
        return $this->status === 'exited'
            && $this->guest_id !== null
            && Auth::guard('guest')->id() === $this->guest_id
            && $this->review === null;
    }

    /**
     * Whether the customer may move it to another date themselves: a paid
     * or confirmed onsite reservation, before the cutoff, within the limit.
     */
    public function canBeRescheduledByCustomer(): bool
    {
        $settings = BookingSetting::current();

        return $this->isOnsite()
            && in_array($this->status, ['confirmed', 'payment_review'], true)
            && $this->drop_off_on !== null
            && $settings->reschedule_enabled
            && $this->reschedule_count < $settings->max_reschedules
            && now()->lessThanOrEqualTo($this->drop_off_on->startOfDay()->subHours($settings->reschedule_cutoff_hours));
    }

    /**
     * Where the customer pays this booking's fee.
     *
     * @return list<array{key: string, label: string, qr: string|null, accountName: string|null, accountNumber: string|null}>
     */
    public function paymentMethods(): array
    {
        return $this->branch?->paymentMethods() ?? PaymentSetting::instance()->methods();
    }

    /**
     * The price as the customer sees it: "reviewing" until the admin has
     * seen the unit and its size class has a price for every service.
     *
     * With a referral discount, the total is what is left after it.
     *
     * @return array{status: string, total: int|null, subtotal: int|null, discount: int|null, items: list<array{name: string, price: int|null}>}
     */
    public function priceForCustomer(): array
    {
        $set = $this->total_price !== null && $this->priced_at !== null;
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return [
            'status' => $set ? 'set' : 'reviewing',
            'total' => $set ? $this->total_price : null,
            'subtotal' => $set ? $this->total_price + (int) $this->discount_amount : null,
            'discount' => $set && $this->discount_amount ? $this->discount_amount : null,
            'items' => $items->map(fn (BookingItem $item) => ['name' => $item->service_name, 'price' => $set ? $item->price : null])->values()->all(),
        ];
    }

    /**
     * Shape used by the customer's pages (/api/my-bookings).
     *
     * @return array<string, mixed>
     */
    public function toCustomer(?string $key = null): array
    {
        $stage = $this->stage();
        $paying = in_array($stage, ['awaiting_payment', 'payment_review'], true);
        $branch = $this->branch;
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $reviewed = $this->size_reviewed_at !== null && $this->vehicle_size_name !== null;

        return [
            'id' => $this->getKey(),
            'reference' => $this->reference,
            'url' => $this->customerPath(),
            'kind' => $this->kind,
            'status' => $this->status,
            'stage' => $stage,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'phoneHref' => $branch->phoneHref(),
                'messenger' => $branch->messenger_url,
                'map' => $branch->map(),
            ] : null,
            'dropOffOn' => $this->drop_off_on?->toDateString(),
            'readyOn' => $this->ready_on?->toDateString(),
            'estimate' => $this->estimate()->toArray(),
            'when' => $this->whenLabel(),
            'preferredDate' => $this->preferred_date?->toDateString(),
            'preferredWindow' => $this->preferred_window,
            'vehicle' => [
                'id' => $this->vehicle_id,
                'year' => $this->vehicle_year,
                'model' => $this->vehicle_model,
                'type' => $this->vehicle_type,
                'typeLabel' => $this->vehicle_type ? (self::VEHICLE_TYPES[$this->vehicle_type] ?? $this->vehicle_type) : null,
                'plate' => $this->plate_number,
                'size' => $reviewed ? $this->vehicle_size_name : null,
                'sizeReviewed' => $reviewed,
            ],
            'items' => $items->map->toCustomer()->values()->all(),
            'serviceIds' => $items->pluck('service_id')->filter()->values()->all(),
            'price' => $this->priceForCustomer(),
            'address' => $this->isHome() ? $this->address : null,
            'area' => $this->isHome() ? $this->area : null,
            'amountDue' => $this->amount_due,
            'balance' => $this->balance(),
            'holdExpiresAt' => $stage === 'awaiting_payment' ? $this->hold_expires_at?->toIso8601String() : null,
            'arrivedAt' => $this->arrived_at?->toIso8601String(),
            'exitedAt' => $this->exited_at?->toIso8601String(),
            'payment' => [
                'method' => $this->payment_method,
                'submittedAt' => $this->payment_submitted_at?->toIso8601String(),
                'rejectedReason' => $stage === 'awaiting_payment' ? $this->payment_rejected_reason : null,
                'proofUrl' => $this->payment_proof
                    ? url("/api/my-bookings/{$this->getKey()}/proof").($key ? '?key='.rawurlencode($key) : '')
                    : null,
                'paidAt' => $this->paid_at?->toIso8601String(),
            ],
            'methods' => $paying ? $this->paymentMethods() : [],
            'instructions' => $paying ? PaymentSetting::instance()->instructions : null,
            'canCancel' => $this->canBeCancelledByCustomer(),
            'canReschedule' => $this->canBeRescheduledByCustomer(),
            'canReview' => $this->canBeReviewed(),
            'review' => $this->review?->toCustomer(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
