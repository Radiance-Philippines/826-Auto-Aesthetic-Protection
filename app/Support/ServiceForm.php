<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\VehicleSize;

/**
 * What the admin's service form and list work out, for both admins: the
 * price grid (one price per size class) and the list's labels.
 */
final class ServiceForm
{
    /**
     * The longest job, in open days.
     */
    public const MAX_DAYS = 60;

    /**
     * The service's rates as the form edits them.
     *
     * @return array<int, array{price: int|null}>
     */
    public static function rates(?Service $service): array
    {
        $rates = [];

        foreach (VehicleSize::query()->ordered()->get() as $size) {
            $rates[$size->id] = ['price' => $service?->rates->firstWhere('vehicle_size_id', $size->id)?->price];
        }

        return $rates;
    }

    /**
     * Saves the grid: a price for each size class that has one, and no
     * rate for the classes left blank.
     *
     * @param  array<int|string, array<string, mixed>>  $rates
     */
    public static function saveRates(Service $service, array $rates): void
    {
        foreach (VehicleSize::query()->pluck('id') as $sizeId) {
            $price = ($rates[$sizeId] ?? $rates[(string) $sizeId] ?? [])['price'] ?? null;

            if (blank($price)) {
                ServiceRate::query()->where('service_id', $service->id)->where('vehicle_size_id', $sizeId)->delete();

                continue;
            }

            ServiceRate::updateOrCreate(
                ['service_id' => $service->id, 'vehicle_size_id' => $sizeId],
                ['price' => (int) $price],
            );
        }
    }

    public static function takesLabel(?int $days): string
    {
        return $days > 0 ? ($days === 1 ? '1 day' : "{$days} days") : 'Not set';
    }

    /**
     * "₱2,500 – ₱4,000" over the classes that have a price. Needs the rates loaded.
     */
    public static function priceLabel(Service $service): string
    {
        $prices = $service->rates->filter->hasPrice()->pluck('price');

        return $prices->isEmpty() ? 'Not set' : '₱'.number_format($prices->min()).($prices->min() !== $prices->max() ? ' – ₱'.number_format($prices->max()) : '');
    }
}
