<?php

namespace App\Support\Schedule;

/**
 * How long a job keeps a car in the shop, in open days (never hours).
 */
final readonly class Estimate
{
    public function __construct(public int $days = 0) {}

    public function isEmpty(): bool
    {
        return $this->days <= 0;
    }

    /**
     * "1 day", "about 3 days"; blank when there is no estimate.
     */
    public function label(): string
    {
        return match (true) {
            $this->days <= 0 => '',
            $this->days === 1 => '1 day',
            default => "about {$this->days} days",
        };
    }

    /**
     * @return array{days: int, label: string}
     */
    public function toArray(): array
    {
        return ['days' => $this->days, 'label' => $this->label()];
    }
}
