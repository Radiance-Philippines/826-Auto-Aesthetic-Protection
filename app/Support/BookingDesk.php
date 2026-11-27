<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\User;
use App\Support\Schedule\Estimate;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The team's side of a booking: the list's tabs, which steps a booking is
 * offered and what the steps that are more than one line do. Shared by the
 * Filament resource and the React admin.
 */
final class BookingDesk
{
    /** The list's tabs, in order; the first one shown is DEFAULT_TAB */
    public const TABS = [
        'action' => 'Needs you',
        'size' => 'Needs size review',
        'today' => 'Today',
        'in_shop' => 'In the shop',
        'upcoming' => 'Upcoming',
        'holds' => 'Unpaid holds',
        'home' => 'Home service',
        'past' => 'Done',
        'cancelled' => 'Cancelled',
        'all' => 'All',
    ];

    public const DEFAULT_TAB = 'upcoming';

    /** Tabs that show how many wait in them */
    public const COUNTED_TABS = ['action', 'size'];

    /** The queue steps, by action name: the status each one leads to */
    public const STEPS = [
        'markArrived' => 'arrived',
        'startWork' => 'in_progress',
        'qualityCheck' => 'quality_check',
        'markReady' => 'ready',
        'markExited' => 'exited',
    ];

    /** The area each action on a booking needs */
    public const AREAS = [
        'markPaid' => 'bookings.sales',
        'rejectProof' => 'bookings.sales',
        'setSize' => 'bookings.sales',
        'scheduleHome' => 'bookings.sales',
        'markArrived' => 'queue.update',
        'startWork' => 'queue.update',
        'qualityCheck' => 'queue.update',
        'markReady' => 'queue.update',
        'markExited' => 'queue.update',
        'assignedTo' => 'queue.update',
        'reschedule' => 'bookings.sales',
        'changeReadyDate' => 'queue.update',
        'noShow' => 'bookings.sales',
        'cancelBooking' => 'bookings.sales',
    ];

    /**
     * Narrows a bookings query to one of the tabs.
     */
    public static function tab(Builder $query, string $tab): Builder
    {
        $today = today()->toDateString();

        return match ($tab) {
            'action' => $query->needsAction(),
            'size' => $query->needsSizeReview(),
            'today' => $query->open()->where('drop_off_on', $today),
            'in_shop' => $query->whereIn('status', Booking::IN_SHOP),
            'upcoming' => $query->open()
                ->where(fn (Builder $open) => $open->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now()))
                ->where(fn (Builder $future) => $future->where('drop_off_on', '>=', $today)->orWhereNull('drop_off_on')),
            'holds' => $query->where('status', 'awaiting_payment')->where(fn (Builder $hold) => $hold->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now())),
            'home' => $query->where('kind', 'home')->open(),
            'past' => $query->whereIn('status', ['exited', 'no_show'])->reorder('drop_off_on', 'desc'),
            'cancelled' => $query->where(fn (Builder $closed) => $closed
                ->whereIn('status', ['cancelled', 'declined', 'expired'])
                ->orWhere(fn (Builder $lapsed) => $lapsed->lapsedHolds()))
                ->reorder('updated_at', 'desc'),
            default => $query,
        };
    }

    /**
     * Whether this booking is at a point where the action makes sense.
     */
    public static function offers(string $action, Booking $booking): bool
    {
        if (isset(self::STEPS[$action])) {
            return $booking->nextStatus() === self::STEPS[$action];
        }

        return match ($action) {
            'markPaid' => in_array($booking->stage(), ['awaiting_payment', 'payment_review'], true),
            'rejectProof' => $booking->status === 'payment_review',
            'setSize', 'assignedTo', 'cancelBooking' => $booking->isOpen(),
            'scheduleHome' => $booking->isHome() && in_array($booking->status, ['requested', 'awaiting_payment', 'confirmed'], true),
            'noShow' => $booking->status === 'confirmed' && (bool) $booking->drop_off_on?->isBefore(today()),
            'reschedule' => $booking->isOnsite() && in_array($booking->status, ['awaiting_payment', 'payment_review', 'confirmed'], true) && $booking->stage() !== 'expired',
            'changeReadyDate' => $booking->canChangeReadyDate(),
            default => false,
        };
    }

    /**
     * Offered, and this person's areas include it.
     */
    public static function allows(User $user, string $action, Booking $booking): bool
    {
        return $user->canAccessArea(self::AREAS[$action]) && self::offers($action, $booking);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'requested', 'payment_review' => 'warning',
            'awaiting_payment' => 'info',
            'confirmed' => 'primary',
            'arrived', 'in_progress' => 'info',
            'quality_check' => 'warning',
            'ready', 'exited' => 'success',
            'no_show' => 'danger',
            default => 'gray',
        };
    }

    public static function statusLabel(Booking $booking): string
    {
        return $booking->stage() === 'expired' ? Booking::STATUSES['expired'] : (Booking::STATUSES[$booking->status] ?? $booking->status);
    }

    /**
     * "0917 758 6820" → "tel:+639177586820"; null when it isn't a number.
     */
    public static function phoneUrl(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) < 7) {
            return null;
        }

        $number = match (true) {
            str_starts_with($digits, '63') => "+{$digits}",
            str_starts_with($digits, '09') => '+63'.substr($digits, 1),
            default => (str_starts_with(trim((string) $phone), '+') ? '+' : '').$digits,
        };

        return "tel:{$number}";
    }

    public static function smsUrl(Booking $booking): ?string
    {
        $number = self::phoneUrl($booking->phone);

        return $number === null ? null : 'sms:'.substr($number, 4).'?body='.rawurlencode("Hi {$booking->name}, this is 826 {$booking->branch?->name} about your booking {$booking->reference}.");
    }

    public static function mailUrl(Booking $booking): ?string
    {
        return $booking->email ? 'mailto:'.$booking->email.'?subject='.rawurlencode("Your 826 booking {$booking->reference}") : null;
    }

    /**
     * Branches this team member may book for.
     *
     * @return array<int, string>
     */
    public static function branchOptions(?User $user): array
    {
        return Branch::query()->visibleTo($user)->ordered()->pluck('name', 'id')->all();
    }

    /**
     * The services a branch offers that can be booked, as
     * "Graphene coating · 3 days".
     *
     * @return array<int, string>
     */
    public static function serviceOptions(mixed $branchId, bool $home = false): array
    {
        if (blank($branchId)) {
            return [];
        }

        return Service::query()
            ->bookable()
            ->whereHas('branches', fn (Builder $branches) => $branches->whereKey($branchId))
            ->when($home, fn (Builder $services) => $services->where('home_service_ok', true))
            ->ordered()
            ->get()
            ->mapWithKeys(fn (Service $service) => [$service->id => "{$service->name} · ".(new Estimate((int) $service->estimate_days))->label()])
            ->all();
    }

    /**
     * A booking the team takes at the counter, over the phone or on Messenger.
     * Onsite ones are checked against the day's cap like the site's and
     * confirmed straight away.
     *
     * @param  array<string, mixed>  $data  the form, by column name, with branch_id, services, kind, source and force
     *
     * @throws ValidationException
     * @throws LockTimeoutException
     */
    public static function book(User $user, array $data): Booking
    {
        $branch = Branch::query()->visibleTo($user)->findOrFail($data['branch_id']);
        $home = ($data['kind'] ?? 'onsite') === 'home';
        $quote = Reservations::quote($branch, array_map('intval', $data['services'] ?? []), home: $home);

        // The same phone number is the same customer: their units and history stay together
        $guest = Guest::findOrCreateByPhone($data['name'], $data['phone'], $branch->id, $data['email'] ?? null, in_array($data['source'] ?? null, ['walk_in', 'phone'], true) ? $data['source'] : 'admin');

        $details = [
            ...$data,
            'admin_note' => $data['admin_note'] ?? null,
        ];

        if (! $home) {
            return Reservations::reserve(
                $branch,
                $guest,
                $quote,
                CarbonImmutable::parse($data['drop_off_on']),
                $details,
                source: $data['source'] ?? 'admin',
                force: (bool) ($data['force'] ?? false),
            );
        }

        $booking = Reservations::requestHomeService($branch, $guest, $quote, [
            ...$details,
            'preferred_date' => $data['preferred_date'] ?? (filled($data['drop_off_on'] ?? null) ? CarbonImmutable::parse($data['drop_off_on'])->toDateString() : today()->toDateString()),
            'preferred_window' => $data['preferred_window'] ?? 'any',
        ], byStaff: true);

        $booking->update(array_filter([
            'source' => $data['source'],
            'admin_note' => $data['admin_note'] ?? null,
            'read_at' => now(),
        ], fn ($value) => $value !== null));

        return $booking;
    }

    public static function bookedTitle(Booking $booking): string
    {
        return $booking->isHome() ? 'Home service request added' : 'Booked: '.$booking->whenLabel();
    }

    /**
     * After the details are edited: a changed unit needs its size looked at
     * again, and sales is told.
     */
    public static function afterEdit(Booking $booking): void
    {
        if (! $booking->wasChanged(['vehicle_year', 'vehicle_model', 'vehicle_type', 'plate_number'])) {
            return;
        }

        $change = $booking->events()->where('kind', 'unit')->first();

        $booking->update(['priced_at' => null]);

        HeadsUp::send($booking, 'the unit', $change?->from, $change?->to);
    }

    /**
     * Declines a home-service request, or cancels a booking; the customer gets an email.
     */
    public static function cancel(Booking $booking, ?string $reason): void
    {
        if ($booking->status === 'requested') {
            $booking->update(['status' => 'declined', 'cancelled_by' => 'staff', 'cancel_reason' => $reason]);
            Notifier::customer($booking, 'declined');

            return;
        }

        Reservations::cancel($booking, 'staff', $reason);
        Notifier::customer($booking, 'cancelled');
    }

    /**
     * The team moves an onsite booking to another drop-off date.
     *
     * @throws ValidationException
     * @throws LockTimeoutException
     */
    public static function reschedule(Booking $booking, CarbonImmutable $date, bool $force = false): void
    {
        Reservations::reschedule($booking, $date, byCustomer: false, force: $force);
        Notifier::customer($booking->refresh(), 'rescheduled');
    }

    /**
     * A home-service request gets its visit date (and the fee, when on).
     */
    public static function scheduleHome(Booking $booking, CarbonImmutable $visit, ?CarbonImmutable $ready, bool $askFee): void
    {
        $payments = PaymentSetting::instance();
        $askFee = $askFee && $booking->status === 'requested' && $payments->feeAmount() > 0;
        $visit = $visit->startOfDay();
        $ready = $ready?->startOfDay() ?? OpenCalendar::for($booking->branch, $visit, $visit)->readyOn($visit, (int) $booking->total_days);

        $booking->update([
            'drop_off_on' => $visit,
            'ready_on' => $ready ?? $visit,
            'status' => $booking->status === 'requested' ? ($askFee ? 'awaiting_payment' : 'confirmed') : $booking->status,
            'amount_due' => $askFee ? $payments->feeAmount() : $booking->amount_due,
            'hold_expires_at' => null,
            'read_at' => $booking->read_at ?? now(),
        ]);
        Notifier::customer($booking, 'home_scheduled');
    }
}
