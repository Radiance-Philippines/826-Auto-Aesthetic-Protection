<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesInsights;
use App\Support\Dashboard\Insights;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Sales for each day of the period (each week, for a long one).
 */
class SalesChart extends ChartWidget
{
    use UsesInsights;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected ?string $emptyStateHeading = 'No finished jobs in this period.';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAccessArea('bookings.sales');
    }

    public function getHeading(): string
    {
        return $this->insights()->period->days() > Insights::WEEKLY_AFTER_DAYS ? 'Sales per week' : 'Sales per day';
    }

    public function getDescription(): string
    {
        return 'Total price of jobs that exited, by the day they exited.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $insights = $this->insights();

        $series = $insights->series();

        if ($series['units'] === 0) {
            return [];
        }

        return [
            'datasets' => [['label' => 'Sales', 'data' => $series['sales']]],
            'labels' => $series['labels'],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: (value) => '₱' + Number(value).toLocaleString() } } },
        }
        JS);
    }
}
