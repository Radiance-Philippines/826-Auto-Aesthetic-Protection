<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BookingStats;
use App\Filament\Widgets\BranchesInsight;
use App\Filament\Widgets\CustomersInsight;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\OperationsInsight;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\SalesOverview;
use App\Filament\Widgets\ServicesInsight;
use App\Filament\Widgets\StaffInsight;
use App\Support\ConsoleUrl;
use App\Support\Dashboard\Cards;
use App\Support\Dashboard\Insights;
use App\Support\Dashboard\Period;
use App\Support\Dashboard\SalesExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The React dashboard: the panel's widgets, each shown to the accounts its
 * canView() allows, with the numbers from App\Support\Dashboard.
 */
class ConsoleDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $insights = $this->insights($request);
        $branches = $insights->visibleBranches();
        $period = $insights->period;

        return response()->json([
            'filters' => [
                'periods' => collect(Period::OPTIONS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
                // One branch: nothing to pick
                'branches' => $branches->count() > 1 ? $branches->map(fn ($branch) => ['value' => (string) $branch->id, 'label' => $branch->name])->values()->all() : [],
                'today' => today()->toDateString(),
            ],
            'canExport' => $user->canAccessArea('bookings.sales'),
            'period' => $period->label(),
            // The panel's own list, as the Filament dashboard picks it
            'widgets' => array_values(array_map(
                fn (string $widget) => $this->widget($widget, $insights),
                array_filter((new Dashboard)->getWidgets(), fn (string $widget) => $widget::canView()),
            )),
        ])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        return SalesExport::download($this->insights($request));
    }

    private function insights(Request $request): Insights
    {
        return Insights::for($request->user(), array_filter($request->only('period', 'from', 'to', 'branch'), 'is_string'));
    }

    /**
     * @param  class-string  $widget
     * @return array<string, mixed>
     */
    private function widget(string $widget, Insights $insights): array
    {
        $stats = fn (string $key, ?string $heading, ?string $description, array $stats) => compact('key', 'heading', 'description') + [
            'type' => 'stats',
            'stats' => array_map(fn (array $stat) => ['url' => ConsoleUrl::from($stat['url'])] + $stat, $stats),
        ];
        $blocks = fn (string $key, string $heading, string $description, array $blocks) => compact('key', 'heading', 'description') + [
            'type' => 'blocks',
            'blocks' => array_map(fn (array $block) => isset($block['items'])
                ? ['items' => array_map(fn (array $item) => ['url' => ConsoleUrl::from($item['url'] ?? null)] + $item, $block['items'])] + $block
                : $block, $blocks),
        ];

        return match ($widget) {
            BookingStats::class => $stats('today', null, null, Cards::today($insights->user)),
            NeedsAttention::class => $stats('attention', 'Needs attention', null, Cards::attention($insights)),
            SalesOverview::class => $stats('sales', 'Sales', Cards::salesDescription($insights), Cards::sales($insights)),
            SalesChart::class => [
                'key' => 'chart',
                'type' => 'chart',
                'heading' => Cards::chartHeading($insights),
                'description' => Cards::CHART_HELP,
                'empty' => Cards::CHART_EMPTY,
                ...$insights->series(),
            ],
            ServicesInsight::class => $blocks('services', 'Services', $insights->period->label(), Cards::services($insights)),
            BranchesInsight::class => $blocks('branches', 'Branches', $insights->period->label(), Cards::branches($insights)),
            OperationsInsight::class => $blocks('operations', 'Operations', Cards::operationsDescription($insights), Cards::operations($insights)),
            CustomersInsight::class => $blocks('customers', 'Customers and reviews', $insights->period->label(), Cards::customers($insights)),
            StaffInsight::class => $blocks('staff', 'Staff', $insights->period->label(), Cards::staff($insights)),
        };
    }
}
