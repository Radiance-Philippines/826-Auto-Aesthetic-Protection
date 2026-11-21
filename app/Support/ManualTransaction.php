<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use App\Models\VehicleSize;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A job done outside the site (walk-in or phone, even one already
 * finished), entered by the team afterwards: no cap or lead-time checks,
 * no emails to the customer. The prices are the ones the team types in
 * (they start from the rates for the unit's size class).
 */
final class ManualTransaction
{
    public const METHODS = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'bank_transfer' => 'Bank transfer',
        'other' => 'Other',
    ];

    /**
     * Where the unit can be: any step of the queue.
     */
    public const STATUSES = ['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'exited'];

    /**
     * What each service costs for a size class, from the rates, as the
     * starting point for the prices the team types over.
     *
     * @param  list<int>  $serviceIds
     * @return array<int, int|null>
     */
    public static function ratesFor(int $sizeId, array $serviceIds): array
    {
        $rates = ServiceRate::query()->where('vehicle_size_id', $sizeId)->whereIn('service_id', $serviceIds)->pluck('price', 'service_id');

        return collect($serviceIds)->mapWithKeys(fn (int $id) => [$id => $rates->get($id)])->all();
    }

    /**
     * Saves the job as a booking with source "manual".
     *
     * @param  array{branch_id: int|string, guest_id?: int|string|null, name?: string|null, phone?: string|null, vehicle_year: int|string, vehicle_model: string, vehicle_type: string, plate_number: string, vehicle_size_id: int|string, services: list<int|string>, prices?: array<int|string, mixed>, service_date: string, status?: string, discount?: int|string|null, amount_paid?: int|string|null, payment_method?: string|null, payment_reference?: string|null, admin_note?: string|null}  $data
     */
    public static function record(User $user, array $data): Booking
    {
        $branch = Branch::query()->visibleTo($user)->findOrFail($data['branch_id']);
        $size = VehicleSize::query()->findOrFail($data['vehicle_size_id']);
        $date = CarbonImmutable::parse($data['service_date'])->startOfDay();
        $status = in_array($data['status'] ?? 'exited', self::STATUSES, true) ? ($data['status'] ?? 'exited') : 'exited';

        $services = Service::query()
            ->with('category')
            ->whereHas('branches', fn ($branches) => $branches->whereKey($branch->id))
            ->whereKey(array_map('intval', $data['services']))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($services->isEmpty()) {
            throw ValidationException::withMessages(['services' => 'Pick at least one service from this branch.']);
        }

        $prices = $services->mapWithKeys(fn (Service $service) => [$service->id => max(0, (int) ($data['prices'][$service->id] ?? 0))]);
        $subtotal = (int) $prices->sum();
        $discount = min($subtotal, max(0, (int) ($data['discount'] ?? 0)));
        $paid = max(0, (int) ($data['amount_paid'] ?? 0));
        $noon = $date->setTime(12, 0);

        return DB::transaction(function () use ($user, $data, $branch, $size, $date, $noon, $status, $services, $prices, $subtotal, $discount, $paid) {
            $guest = filled($data['guest_id'] ?? null)
                ? Guest::query()->visibleTo($user)->findOrFail($data['guest_id'])
                : Guest::findOrCreateByPhone((string) $data['name'], (string) $data['phone'], $branch->id);

            if (blank($guest->phone) && filled($data['phone'] ?? null)) {
                $guest->update(['phone' => $data['phone']]);
            }

            $vehicle = Reservations::vehicleFor($guest, [...$data, 'vehicle_size_id' => $size->id]);
            $finished = in_array($status, ['arrived', 'in_progress', 'quality_check', 'ready', 'exited'], true);

            $booking = Booking::create([
                'kind' => 'onsite',
                'source' => 'manual',
                'status' => $status,
                'branch_id' => $branch->id,
                'guest_id' => $guest->id,
                'vehicle_id' => $vehicle->id,
                'drop_off_on' => $date,
                'ready_on' => $date,
                'vehicle_year' => $vehicle->year,
                'vehicle_model' => $vehicle->model,
                'vehicle_type' => $vehicle->type,
                'plate_number' => $vehicle->plate_number,
                'vehicle_size_id' => $size->id,
                'vehicle_size_name' => $size->name,
                'size_reviewed_at' => now(),
                'priced_at' => now(),
                'total_price' => $subtotal - $discount,
                'discount_amount' => $discount ?: null,
                'name' => $guest->name,
                'phone' => (string) $guest->phone,
                'email' => $guest->email,
                'admin_note' => $data['admin_note'] ?? null,
                'total_days' => (int) $services->sum('estimate_days'),
                'amount_paid' => $paid > 0 ? $paid : null,
                'payment_method' => $paid > 0 ? ($data['payment_method'] ?? null) : null,
                'payment_note' => $paid > 0 ? ($data['payment_reference'] ?? null) : null,
                'paid_at' => $paid > 0 ? $noon : null,
                'confirmed_at' => $noon,
                'arrived_at' => $finished ? $noon : null,
                'ready_at' => in_array($status, ['ready', 'exited'], true) ? $noon : null,
                'exited_at' => $status === 'exited' ? $noon : null,
                'read_at' => now(),
            ]);

            foreach ($services->values() as $index => $service) {
                $booking->items()->create([
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'category_name' => $service->category?->name,
                    'price' => $prices[$service->id],
                    'duration_days' => (int) $service->estimate_days,
                    'warranty_months' => $service->warranty_months,
                    'sort_order' => $index,
                ]);
            }

            return $booking;
        });
    }
}
