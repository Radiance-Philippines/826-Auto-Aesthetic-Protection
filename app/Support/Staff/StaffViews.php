<?php

namespace App\Support\Staff;

use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\User;
use App\Support\InboxAccess;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;

/**
 * The shapes the staff app ("826 Team") reads: who the signed-in person is
 * and which tabs they get, and a booking as a queue card or as a unit's
 * detail. What a role may see is decided here from the same areas the admin
 * uses: money only with bookings.sales, the customer's contact only with
 * customers.view or bookings.sales.
 */
final class StaffViews
{
    /**
     * The app's tabs: the order they are shown in.
     *
     * @var list<string>
     */
    public const TABS = ['queue', 'today', 'clients', 'inbox', 'overview', 'me'];

    /**
     * The bottom bar has room for this many; the rest are listed as `overflow`.
     */
    public const MAX_TABS = 5;

    /**
     * What each step's button says; `confirm` steps cannot be undone.
     *
     * @var array<string, array{label: string, confirm: bool}>
     */
    private const STEP_BUTTONS = [
        'arrived' => ['label' => 'Mark arrived', 'confirm' => false],
        'in_progress' => ['label' => 'Start work', 'confirm' => false],
        'quality_check' => ['label' => 'Send to quality check', 'confirm' => false],
        'ready' => ['label' => 'Mark ready', 'confirm' => false],
        'exited' => ['label' => 'Mark as exited', 'confirm' => true],
    ];

    /**
     * History lines about money: only for people who see money.
     */
    private const MONEY_EVENTS = ['paid', 'payment', 'payment_rejected', 'discount'];

    /**
     * `app` (the team app's key) is only there for the new apps: the legacy app's answer is unchanged.
     *
     * @return array{id: int, name: string, email: string, role: string, roleLabel: string, isOwner: bool, areas: list<string>, branches: list<array{id: int, name: string}>, tabs: list<string>, overflow: list<string>, app?: string}
     */
    public static function me(User $user, ?string $app = null): array
    {
        ['tabs' => $tabs, 'overflow' => $overflow] = self::tabs($user, $app);

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'roleLabel' => $user->roleLabel(),
            'isOwner' => $user->isOwner(),
            'areas' => $user->areas(),
            'branches' => Branch::query()->visibleTo($user)->ordered()->get()->map(fn (Branch $branch) => ['id' => $branch->getKey(), 'name' => $branch->name])->values()->all(),
            'tabs' => $tabs,
            'overflow' => $overflow,
            ...($app !== null ? ['app' => $app] : []),
        ];
    }

    /**
     * Queue: queue.update or bookings.view. Today: bookings.sales. Clients:
     * customers.view. Inbox: the inbox area at a branch. Overview: the
     * dashboard area. System: the settings area (only the system app has
     * it). Me: always. Over five, Clients moves to `overflow` (the app
     * reaches it from Me). A team app (config/team_apps.php) shows only its
     * own tabs, in its order, and never has an overflow; no app is the
     * legacy app.
     *
     * @return array{tabs: list<string>, overflow: list<string>}
     */
    public static function tabs(User $user, ?string $app = null): array
    {
        $allowed = [
            'queue' => $user->canAccessAnyArea('queue.update', 'bookings.view'),
            'today' => $user->canAccessArea('bookings.sales'),
            'clients' => $user->canAccessArea('customers.view'),
            'inbox' => InboxAccess::allows($user),
            'overview' => $user->canAccessArea('dashboard'),
            'system' => $user->canAccessArea('settings'),
            'me' => true,
        ];

        if ($app !== null) {
            $own = array_filter(config("team_apps.{$app}.tabs"), fn (string $tab) => $allowed[$tab] ?? false);

            return ['tabs' => [...$own, 'me'], 'overflow' => []];
        }

        $tabs = array_values(array_filter(self::TABS, fn (string $tab) => $allowed[$tab]));
        $overflow = [];

        if (count($tabs) > self::MAX_TABS) {
            $overflow = ['clients'];
            $tabs = array_values(array_diff($tabs, $overflow));
        }

        return ['tabs' => $tabs, 'overflow' => $overflow];
    }

    /**
     * The customer's phone and email: for people who see customers or work the sales side.
     */
    public static function seesContact(User $user): bool
    {
        return $user->canAccessAnyArea('customers.view', 'bookings.sales');
    }

    public static function seesMoney(User $user): bool
    {
        return $user->canAccessArea('bookings.sales');
    }

    public static function firstName(?string $name): string
    {
        return explode(' ', trim((string) $name))[0];
    }

    /**
     * A card on the queue board (and on Today's lists).
     *
     * @return array<string, mixed>
     */
    public static function card(Booking $booking, User $user, ?OpenCalendar $calendar = null): array
    {
        $day = self::dayOf($booking, $calendar);
        $next = self::next($booking);

        return [
            'id' => $booking->getKey(),
            'reference' => $booking->reference,
            'kind' => $booking->kind,
            'stage' => $booking->stage(),
            'name' => self::firstName($booking->name),
            'unit' => $booking->vehicleLabel() ?: null,
            'vehicle' => self::vehicle($booking),
            'services' => $booking->items->pluck('service_name')->values()->all(),
            'servicesLabel' => $booking->servicesLabel(),
            'dropOffOn' => $booking->drop_off_on?->toDateString(),
            'readyOn' => $booking->ready_on?->toDateString(),
            'readyLabel' => $booking->ready_on?->format('D, M j'),
            'dayNumber' => $day,
            'totalDays' => $booking->total_days ?: null,
            'dayLabel' => $day !== null ? "Day {$day} of {$booking->total_days}" : null,
            'home' => $booking->isHome(),
            'needsSize' => $booking->needsSizeReview(),
            'late' => self::isLate($booking),
            'note' => match ($booking->stage()) {
                'awaiting_payment' => 'Waiting for the fee',
                'payment_review' => 'Screenshot to check',
                default => null,
            },
            'next' => $next,
            'canAdvance' => $next !== null && $user->canAccessArea('queue.update'),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
        ];
    }

    /**
     * One unit, in full, for what this user may see.
     *
     * @return array<string, mixed>
     */
    public static function detail(Booking $booking, User $user): array
    {
        $booking->loadMissing(['branch', 'items', 'photos.user', 'events.user']);

        $contact = self::seesContact($user);
        $money = self::seesMoney($user);
        $calendar = $booking->branch ? OpenCalendar::for($booking->branch, CarbonImmutable::today(), CarbonImmutable::today()) : null;
        $card = self::card($booking, $user, $calendar);

        return [
            'id' => $booking->getKey(),
            'reference' => $booking->reference,
            'kind' => $booking->kind,
            'status' => $booking->status,
            'stage' => $booking->stage(),
            'statusLabel' => Booking::STATUSES[$booking->status] ?? $booking->status,
            'branch' => $booking->branch ? ['id' => $booking->branch->getKey(), 'name' => $booking->branch->name] : null,
            'customer' => [
                'firstName' => self::firstName($booking->name),
                'name' => $contact ? $booking->name : null,
                'phone' => $contact ? $booking->phone : null,
                'email' => $contact ? $booking->email : null,
                'guestId' => $contact ? $booking->guest_id : null,
                'address' => $contact && $booking->isHome() ? $booking->address : null,
                'area' => $contact && $booking->isHome() ? $booking->area : null,
            ],
            'customerMessage' => $booking->message,
            'vehicle' => self::vehicle($booking) + ['label' => $booking->vehicleLabel() ?: null],
            'services' => $booking->items->map(fn ($item) => ['name' => $item->service_name, 'category' => $item->category_name, 'estimateDays' => $item->duration_days ?: null])->values()->all(),
            'estimate' => $booking->estimate()->toArray(),
            'dropOffOn' => $card['dropOffOn'],
            'readyOn' => $card['readyOn'],
            'readyLabel' => $card['readyLabel'],
            'preferredDate' => $booking->preferred_date?->toDateString(),
            'dayNumber' => $card['dayNumber'],
            'totalDays' => $card['totalDays'],
            'dayLabel' => $card['dayLabel'],
            'late' => $card['late'],
            'needsSize' => $card['needsSize'],
            'next' => $card['next'],
            'can' => [
                'advance' => $card['canAdvance'],
                'changeReadyDate' => $booking->canChangeReadyDate() && $user->canAccessArea('queue.update'),
                'addNote' => $user->canAccessAnyArea('queue.update', 'bookings.sales'),
                'addPhoto' => $user->canAccessArea('queue.update'),
                'setSize' => $money && $booking->isOpen(),
                'reviewPayment' => $money && $booking->status === 'payment_review',
            ],
            'timeline' => self::timeline($booking),
            'photos' => $booking->photos->map->toStaff()->values()->all(),
            'history' => self::history($booking, $money),
            'money' => $money ? self::money($booking) : null,
        ];
    }

    /**
     * @return array{id: int|null, year: int|null, model: string|null, type: string|null, typeLabel: string|null, plate: string|null, size: string|null}
     */
    private static function vehicle(Booking $booking): array
    {
        return [
            'id' => $booking->vehicle_id,
            'year' => $booking->vehicle_year,
            'model' => $booking->vehicle_model,
            'type' => $booking->vehicle_type,
            'typeLabel' => $booking->vehicle_type ? (Booking::VEHICLE_TYPES[$booking->vehicle_type] ?? $booking->vehicle_type) : null,
            'plate' => $booking->plate_number,
            'size' => $booking->size_reviewed_at ? $booking->vehicle_size_name : null,
        ];
    }

    /**
     * @return array{to: string, label: string, confirm: bool}|null
     */
    private static function next(Booking $booking): ?array
    {
        $to = $booking->nextStatus();

        if ($to === null) {
            return null;
        }

        $button = self::STEP_BUTTONS[$to];

        return ['to' => $to, 'label' => $to === 'exited' && $booking->isHome() ? 'Mark done' : $button['label'], 'confirm' => $button['confirm']];
    }

    /**
     * Still in the shop past its ready date (same rule as the dashboard's "late").
     */
    private static function isLate(Booking $booking): bool
    {
        return $booking->isInShop() && $booking->ready_on !== null && $booking->ready_on->lessThan(today());
    }

    /**
     * "Day n of m": the open days since arriving, counting the arrival day
     * and today, never more than the estimate. Null before arrival or
     * without an estimate.
     */
    private static function dayOf(Booking $booking, ?OpenCalendar $calendar): ?int
    {
        if ($booking->arrived_at === null || $booking->total_days <= 0 || ! $booking->isInShop()) {
            return null;
        }

        $date = $booking->arrived_at->startOfDay();
        $today = CarbonImmutable::today();
        $calendar ??= $booking->branch ? OpenCalendar::for($booking->branch, $date, $today) : null;
        $open = 0;

        for ($i = 0; $i <= OpenCalendar::MAX_WALK_DAYS && $date->lessThanOrEqualTo($today); $i++, $date = $date->addDay()) {
            if ($calendar === null || $calendar->isOpen($date)) {
                $open++;
            }
        }

        return min(max(1, $open), (int) $booking->total_days);
    }

    /**
     * The steps this unit goes through, with when each happened (null = not yet).
     * Arrival, ready and exit have their own columns; the others come from the history.
     *
     * @return list<array{key: string, label: string, at: string|null, done: bool, current: bool}>
     */
    private static function timeline(Booking $booking): array
    {
        $chain = $booking->isHome() ? Booking::NEXT_HOME : Booking::NEXT_ONSITE;
        $steps = array_values(array_unique([...array_keys($chain), ...array_values($chain)]));
        $current = in_array($booking->status, $steps, true) ? $booking->status : null;
        $reached = $current !== null ? array_search($current, $steps, true) : -1;

        $fromHistory = fn (string $status) => $booking->events
            ->where('kind', 'status')
            ->where('to', Booking::STATUSES[$status])
            ->last()?->created_at;

        return collect($steps)->map(function (string $step, int $index) use ($booking, $current, $reached, $fromHistory) {
            $at = match ($step) {
                'confirmed' => $booking->confirmed_at,
                'arrived' => $booking->arrived_at,
                'ready' => $booking->ready_at,
                'exited' => $booking->exited_at,
                default => $fromHistory($step),
            };

            return [
                'key' => $step,
                'label' => $step === 'exited' && $booking->isHome() ? 'Done' : ($step === 'confirmed' ? 'Booked' : Booking::STATUSES[$step]),
                'at' => $at?->toIso8601String(),
                'done' => $index <= $reached || $at !== null,
                'current' => $step === $current,
            ];
        })->all();
    }

    /**
     * The booking's history, newest first, 50 lines. Money lines are left out
     * (and a size line loses its price) for people who don't see money.
     *
     * @return list<array{id: int, kind: string, from: string|null, to: string|null, note: string|null, by: string|null, at: string, line: string}>
     */
    private static function history(Booking $booking, bool $money): array
    {
        return $booking->events
            ->reject(fn (BookingEvent $event) => ! $money && in_array($event->kind, self::MONEY_EVENTS, true))
            ->take(50)
            ->map(function (BookingEvent $event) use ($money) {
                // In memory only: the size line's note is its price
                if (! $money && $event->kind === 'size') {
                    $event->note = null;
                }

                return [
                    'id' => $event->getKey(),
                    'kind' => $event->kind,
                    'from' => $event->from,
                    'to' => $event->to,
                    'note' => $event->note,
                    'by' => $event->user?->name,
                    'at' => $event->created_at->toIso8601String(),
                    'line' => $event->line(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function money(Booking $booking): array
    {
        $price = $booking->priceForCustomer();

        return [
            'priced' => $price['status'] === 'set',
            'total' => $price['total'],
            'subtotal' => $price['subtotal'],
            'discount' => $price['discount'],
            'items' => $price['items'],
            'amountDue' => $booking->amount_due,
            'amountPaid' => $booking->amount_paid,
            'paidAt' => $booking->paid_at?->toIso8601String(),
            'paymentMethod' => $booking->payment_method,
            'paymentSubmittedAt' => $booking->payment_submitted_at?->toIso8601String(),
            'rejectedReason' => $booking->stage() === 'awaiting_payment' ? $booking->payment_rejected_reason : null,
            'proofUrl' => $booking->payment_proof ? url("/api/staff/bookings/{$booking->getKey()}/proof") : null,
        ];
    }
}
