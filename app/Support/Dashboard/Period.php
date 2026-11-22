<?php

namespace App\Support\Dashboard;

use Carbon\CarbonImmutable;

/**
 * The stretch of days the dashboard looks at: whole days, first to last.
 */
final class Period
{
    public const DEFAULT = 'last_30';

    /**
     * @var array<string, string>
     */
    public const OPTIONS = [
        'today' => 'Today',
        'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'custom' => 'Custom dates',
    ];

    public function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * From the page's filters: a custom range needs both dates (swapped if
     * backwards); anything unknown falls back to the last 30 days.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function from(?array $filters): self
    {
        $key = (string) ($filters['period'] ?? self::DEFAULT);
        $today = today()->toImmutable();

        if ($key === 'custom') {
            $from = self::date($filters['from'] ?? null);
            $to = self::date($filters['to'] ?? null);

            if ($from && $to) {
                return $from <= $to ? new self('custom', $from, $to) : new self('custom', $to, $from);
            }

            $key = self::DEFAULT;
        }

        return match ($key) {
            'today' => new self('today', $today, $today),
            'last_7' => new self('last_7', $today->subDays(6), $today),
            'this_month' => new self('this_month', $today->startOfMonth(), $today),
            'last_month' => new self('last_month', $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            'this_year' => new self('this_year', $today->startOfYear(), $today),
            default => new self(self::DEFAULT, $today->subDays(29), $today),
        };
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse((string) $value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function start(): CarbonImmutable
    {
        return $this->from->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return $this->to->endOfDay();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function label(): string
    {
        if ($this->from->isSameDay($this->to)) {
            return $this->from->format('M j, Y');
        }

        return $this->from->format('M j, Y').' to '.$this->to->format('M j, Y');
    }
}
