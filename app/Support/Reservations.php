<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Schedule\Availability;
use App\Support\Schedule\Estimate;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Making, moving, pricing and cancelling reservations. Anything that
 * checks a branch's daily cap and then writes runs under that branch's
 * lock, so two customers can't take the last place of a day at the same
 * moment.
 */
final class Reservations
{
    public const LOCK_SECONDS = 10;

    public const WAIT_SECONDS = 5;

    /**
     * Runs the callback while holding the branch's booking lock. Throws
     * LockTimeoutException when another booking keeps it too long.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function underLock(Branch|int $branch, Closure $callback): mixed
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        return Cache::lock("booking:branch:{$id}", self::LOCK_SECONDS)->block(self::WAIT_SECONDS, $callback);
    }

    /**
     * The services at a branch, checked: active, offered there, with a day
     * estimate (and doable at home, for home service). Throws a validation
     * error naming the field to fix.
     *
     * @param  list<int>  $serviceIds
     * @return array{services: Collection<int, Service>, estimate: Estimate}
     */
    public static function quote(Branch $branch, array $serviceIds, bool $home = false): array
    {
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));

        if ($serviceIds === []) {
            throw ValidationException::withMessages(['services' => 'Pick at least one service.']);
        }

        $found = $branch->services()->bookable()->whereKey($serviceIds)->with('category')->get()->keyBy('id');
        $services = collect();

        foreach ($serviceIds as $id) {
            /** @var Service|null $service */
            $service = $found->get($id);

            if (! $service) {
                throw ValidationException::withMessages(['services' => 'One of those services isn’t offered at '.$branch->name.'. Check your picks.']);
            }

            if ($home && ! $service->home_service_ok) {
                throw ValidationException::withMessages(['services' => "{$service->name} needs the shop, so it can’t be done at home."]);
            }

            $services->push($service);
        }

        $services = $services->sortBy(fn (Service $service) => [$service->category?->sort_order ?? 0, $service->sort_order, $service->id])->values();

        return ['services' => $services, 'estimate' => new Estimate((int) $services->sum('estimate_days'))];
    }

    /**
     * The customer's unit for a booking: one of their saved units
     * (vehicle_id), or the one they typed (the same plate reuses the saved
     * unit; a changed year, model or type needs a new size review). A
     * team member may give the size class up front.
     *
     * @param  array<string, mixed>  $details
     */
    public static function vehicleFor(?Guest $guest, array $details): Vehicle
    {
        if (filled($details['vehicle_id'] ?? null)) {
            $vehicle = $guest?->vehicles()->find((int) $details['vehicle_id']);

            if (! $vehicle) {
                throw ValidationException::withMessages(['plate_number' => 'Pick one of your saved units, or add a new one.']);
            }

            return $vehicle;
        }

        $attributes = [
            'year' => (int) $details['vehicle_year'],
            'model' => trim((string) $details['vehicle_model']),
            'type' => (string) $details['vehicle_type'],
        ];
        $plate = Booking::normalizePlate((string) $details['plate_number']);
        $vehicle = $guest?->vehicles()->where('plate_number', $plate)->first();

        if ($vehicle) {
            $vehicle->fill($attributes);

            if ($vehicle->isDirty(['year', 'model', 'type'])) {
                $vehicle->fill(['vehicle_size_id' => null, 'size_reviewed_at' => null]);
            }
        } else {
            $vehicle = new Vehicle([...$attributes, 'plate_number' => $plate, 'guest_id' => $guest?->id]);
        }

        if (filled($details['vehicle_size_id'] ?? null)) {
            $vehicle->fill(['vehicle_size_id' => (int) $details['vehicle_size_id'], 'size_reviewed_at' => now()]);
        }

        $vehicle->save();

        return $vehicle;
    }

    /**
     * Reserves a drop-off date, re-checked under the lock. With the fee on,
     * the day is held for the customer to pay; otherwise it's confirmed.
     * A unit that was reviewed before is priced on the spot; any other
     * waits for the admin's size review.
     *
     * @param  array{services: Collection<int, Service>, estimate: Estimate}  $quote
     * @param  array<string, mixed>  $details  name, phone, email, message, and the unit (vehicle_id, or vehicle_year, vehicle_model, vehicle_type, plate_number)
     */
    public static function reserve(Branch $branch, ?Guest $guest, array $quote, CarbonImmutable $date, array $details, string $source = 'site', bool $force = false): Booking
    {
        $byStaff = $source !== 'site';
        $date = $date->startOfDay();

        return self::underLock($branch, function () use ($branch, $guest, $quote, $date, $details, $source, $byStaff, $force) {
            if ($guest && ! $byStaff) {
                self::checkGuestLimits($guest);
            }

            $result = Availability::check($branch, $date, byStaff: $byStaff);

            if ($result !== 'ok' && ! ($byStaff && $force)) {
                throw ValidationException::withMessages(['date' => self::dateMessage($result, $byStaff)]);
            }

            $owner = ! $byStaff && filled($details['referral_code'] ?? null) ? Referrals::ownerFor($guest, (string) $details['referral_code']) : null;

            // Bookings the team takes are settled at the counter
            $payments = PaymentSetting::instance();
            $fee = ! $byStaff && $payments->feeEnabled() ? $payments->feeAmount() : 0;

            return DB::transaction(function () use ($branch, $guest, $quote, $date, $details, $source, $fee, $payments, $owner) {
                $vehicle = self::vehicleFor($guest, $details);

                $booking = Booking::create([
                    ...self::onlyDetails($details),
                    ...self::vehicleDetails($vehicle),
                    'kind' => 'onsite',
                    'source' => $source,
                    'status' => $fee > 0 ? 'awaiting_payment' : 'confirmed',
                    'branch_id' => $branch->id,
                    'guest_id' => $guest?->id,
                    'drop_off_on' => $date,
                    'ready_on' => OpenCalendar::for($branch, $date, $date)->readyOn($date, $quote['estimate']->days),
                    'total_days' => $quote['estimate']->days,
                    'amount_due' => $fee > 0 ? $fee : null,
                    'hold_expires_at' => $fee > 0 ? now()->addMinutes($payments->holdMinutes()) : null,
                    'email' => filled($details['email'] ?? null) ? $details['email'] : $guest?->email,
                    'read_at' => $source === 'site' ? null : now(),
                ]);

                if ($owner) {
                    Referrals::redeem($booking, $owner);
                }

                self::saveItems($booking, $quote['services']);
                self::priceIfReviewed($booking, $vehicle);

                return $booking;
            });
        });
    }

    /**
     * A home-service request: no date held and no price yet; the branch
     * reviews the unit, then calls to schedule it.
     *
     * @param  array{services: Collection<int, Service>, estimate: Estimate}  $quote
     * @param  array<string, mixed>  $details
     */
    public static function requestHomeService(Branch $branch, ?Guest $guest, array $quote, array $details, bool $byStaff = false): Booking
    {
        if ($guest && ! $byStaff) {
            self::checkGuestLimits($guest);
        }

        return DB::transaction(function () use ($branch, $guest, $quote, $details) {
            $vehicle = self::vehicleFor($guest, $details);

            $booking = Booking::create([
                ...self::onlyDetails($details),
                ...self::vehicleDetails($vehicle),
                'kind' => 'home',
                'source' => 'site',
                'status' => 'requested',
                'branch_id' => $branch->id,
                'guest_id' => $guest?->id,
                'preferred_date' => $details['preferred_date'],
                'preferred_window' => $details['preferred_window'] ?? 'any',
                'address' => $details['address'],
                'area' => $details['area'] ?? null,
                'landmark' => $details['landmark'] ?? null,
                'has_water' => $details['has_water'] ?? null,
                'has_power' => $details['has_power'] ?? null,
                'has_shade' => $details['has_shade'] ?? null,
                'total_days' => $quote['estimate']->days,
                'email' => filled($details['email'] ?? null) ? $details['email'] : $guest?->email,
            ]);

            self::saveItems($booking, $quote['services']);
            self::priceIfReviewed($booking, $vehicle);

            return $booking;
        });
    }

    /**
     * Moves an onsite reservation to another drop-off date, keeping its
     * services, payment and estimate. Staff can book over a full day on
     * purpose with $force.
     */
    public static function reschedule(Booking $booking, CarbonImmutable $date, bool $byCustomer = true, bool $force = false): Booking
    {
        $branch = $booking->branch;
        $date = $date->startOfDay();

        return self::underLock($branch, function () use ($booking, $branch, $date, $byCustomer, $force) {
            $booking->refresh();

            if ($byCustomer && ! $booking->canBeRescheduledByCustomer()) {
                throw ValidationException::withMessages(['date' => 'This booking can’t be moved online anymore. Call or message the branch.']);
            }

            $result = Availability::check($branch, $date, $booking->id, byStaff: ! $byCustomer);

            if ($result !== 'ok' && ! $force) {
                throw ValidationException::withMessages(['date' => self::dateMessage($result, ! $byCustomer)]);
            }

            $booking->update([
                'drop_off_on' => $date,
                'ready_on' => OpenCalendar::for($branch, $date, $date)->readyOn($date, (int) $booking->total_days),
                'reschedule_count' => $byCustomer ? $booking->reschedule_count + 1 : $booking->reschedule_count,
                'reminded_at' => null,
                'read_at' => $byCustomer ? null : $booking->read_at,
            ]);

            return $booking;
        });
    }

    public static function cancel(Booking $booking, string $by, ?string $reason = null): Booking
    {
        $booking->update([
            'status' => 'cancelled',
            'cancelled_by' => $by,
            'cancel_reason' => $reason,
            'read_at' => $by === 'guest' ? null : $booking->read_at,
        ]);

        return $booking;
    }

    /**
     * Moves a unit one step along the queue (booked, arrived, in progress,
     * quality check, ready, exited), recording the arrival and exit times,
     * and tells the customer when it arrives and when it is ready.
     */
    public static function advance(Booking $booking): Booking
    {
        $next = $booking->nextStatus();

        if ($next === null) {
            return $booking;
        }

        $booking->update(['status' => $next, 'read_at' => $booking->read_at ?? now()]);

        if ($booking->isOnsite() && in_array($next, ['arrived', 'ready'], true)) {
            Notifier::customer($booking, $next);
        }

        return $booking;
    }

    /**
     * The admin has seen the unit: gives it its size class and prices the
     * booking from what each service costs for that class. A service with
     * no price for the class leaves the booking unpriced (and still in the
     * review list); the names of those services are returned.
     *
     * @return list<string>
     */
    public static function applySize(Booking $booking, VehicleSize $size, bool $updateVehicle = true): array
    {
        return DB::transaction(function () use ($booking, $size, $updateVehicle) {
            if ($updateVehicle) {
                $booking->vehicle?->update(['vehicle_size_id' => $size->id, 'size_reviewed_at' => now()]);
            }

            $items = $booking->items()->get();
            $prices = ServiceRate::query()
                ->where('vehicle_size_id', $size->id)
                ->whereIn('service_id', $items->pluck('service_id')->filter())
                ->whereNotNull('price')
                ->pluck('price', 'service_id');

            $missing = [];

            foreach ($items as $item) {
                $price = $item->service_id ? $prices->get($item->service_id) : null;
                $item->update(['price' => $price]);

                if ($price === null) {
                    $missing[] = $item->service_name;
                }
            }

            $subtotal = $missing === [] ? (int) $items->sum(fn ($item) => $item->price) : null;
            $discount = Referrals::discountFor($booking, $subtotal);

            $booking->update([
                'vehicle_size_id' => $size->id,
                'vehicle_size_name' => $size->name,
                'size_reviewed_at' => now(),
                'total_price' => $subtotal === null ? null : $subtotal - (int) $discount,
                'discount_amount' => $discount,
                'priced_at' => $missing === [] ? now() : null,
            ]);

            return $missing;
        });
    }

    /**
     * A saved unit's size class, set from the customer's page: the unit is
     * reviewed, and its open bookings that were waiting for a price get one.
     * Returns the bookings that are now priced.
     *
     * @return list<Booking>
     */
    public static function setVehicleSize(Vehicle $vehicle, VehicleSize $size): array
    {
        $vehicle->update(['vehicle_size_id' => $size->id, 'size_reviewed_at' => now()]);
        $priced = [];

        $vehicle->bookings()->open()->whereNull('priced_at')->get()->each(function (Booking $booking) use ($size, &$priced) {
            if (self::applySize($booking, $size, updateVehicle: false) === []) {
                $priced[] = $booking;
            }
        });

        return $priced;
    }

    /**
     * One unpaid hold at a time, and only so many upcoming bookings, per
     * customer (Booking rules), so nobody can sit on every day.
     */
    public static function checkGuestLimits(Guest $guest): void
    {
        $settings = BookingSetting::current();

        $holds = $guest->bookings()
            ->where('status', 'awaiting_payment')
            ->where(fn ($hold) => $hold->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now()))
            ->count();

        if ($settings->max_unpaid_holds_per_guest > 0 && $holds >= $settings->max_unpaid_holds_per_guest) {
            throw ValidationException::withMessages(['date' => 'You have a booking waiting for its reservation fee. Pay or cancel it in My bookings first.']);
        }

        $upcoming = $guest->bookings()
            ->open()
            ->where(fn ($open) => $open->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', now()))
            ->where(fn ($future) => $future->whereNull('drop_off_on')->orWhere('ready_on', '>=', today()->toDateString())->orWhereNull('ready_on'))
            ->count();

        if ($settings->max_upcoming_per_guest > 0 && $upcoming >= $settings->max_upcoming_per_guest) {
            throw ValidationException::withMessages(['date' => "You already have {$upcoming} upcoming bookings. Call or message the branch to book more."]);
        }
    }

    /**
     * What a date check's answer means to the person who picked it.
     */
    public static function dateMessage(string $result, bool $byStaff = false): string
    {
        return match ($result) {
            'full' => $byStaff
                ? 'That day is full. Pick another date, or turn on “Book even if it’s full”.'
                : 'That day is fully booked. Pick another date.',
            'too_soon' => 'That date is too soon. Pick a later date.',
            'too_far' => 'That date is too far ahead to book yet.',
            default => $byStaff
                ? 'The branch is closed that day. Pick another date, or turn on “Book even if it’s full”.'
                : 'The branch is closed that day. Pick another date.',
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function onlyDetails(array $details): array
    {
        return array_intersect_key($details, array_flip(['name', 'phone', 'email', 'message', 'admin_note']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function vehicleDetails(Vehicle $vehicle): array
    {
        return [
            'vehicle_id' => $vehicle->id,
            'vehicle_year' => $vehicle->year,
            'vehicle_model' => $vehicle->model,
            'vehicle_type' => $vehicle->type,
            'plate_number' => $vehicle->plate_number,
        ];
    }

    /**
     * A unit that was reviewed before is priced at once; if its class has
     * no price for a service, the booking waits for the admin like a new one.
     */
    private static function priceIfReviewed(Booking $booking, Vehicle $vehicle): void
    {
        $size = $vehicle->isReviewed() ? $vehicle->vehicleSize : null;

        if ($size) {
            self::applySize($booking, $size, updateVehicle: false);
        }
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    private static function saveItems(Booking $booking, Collection $services): void
    {
        foreach ($services->values() as $index => $service) {
            $booking->items()->create([
                'service_id' => $service->id,
                'service_name' => $service->name,
                'category_name' => $service->category?->name,
                'duration_days' => (int) $service->estimate_days,
                'warranty_months' => $service->warranty_months,
                'sort_order' => $index,
            ]);
        }
    }
}
