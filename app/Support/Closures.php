<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A closure as the admin's form takes it: a first and a last whole day.
 */
final class Closures
{
    /**
     * Whole days become midnight to midnight.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    public static function span(string $firstDay, ?string $lastDay): array
    {
        return [
            'starts_at' => CarbonImmutable::parse($firstDay)->startOfDay(),
            'ends_at' => CarbonImmutable::parse($lastDay ?? $firstDay)->addDay()->startOfDay(),
        ];
    }
}
