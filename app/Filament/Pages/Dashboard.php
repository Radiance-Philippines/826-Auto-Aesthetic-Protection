<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ShownInItsPanels;
use App\Providers\Filament\PanelPages;
use App\Support\Dashboard\Insights;
use App\Support\Dashboard\Period;
use App\Support\Panels;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dashboard (the home page of /owner and /sales, and /operations/dashboard), for accounts with the dashboard area: today at the
 * branches, then sales, services, branches, operations, customers and
 * staff for the chosen period and branch. A widget only shows to the
 * roles that may see its numbers. The filters stay in the address.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;
    use ShownInItsPanels;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAccessArea('dashboard');
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    /**
     * The queue is the operations home, so its dashboard has its own address.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return $panel->getId() === Panels::OPERATIONS ? '/dashboard' : '/';
    }

    /**
     * The widgets of the panel on screen: the master set in /owner, the
     * sales side in /sales, the day-to-day numbers in /operations. Each
     * widget still shows only to the accounts that may see its numbers.
     *
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return match (Filament::getCurrentOrDefaultPanel()->getId()) {
            Panels::SALES => PanelPages::salesWidgets(),
            Panels::OPERATIONS => PanelPages::operationsWidgets(),
            default => PanelPages::ownerWidgets(),
        };
    }

    public function filtersForm(Schema $schema): Schema
    {
        $user = auth()->user();
        $branches = Insights::for($user, null)->visibleBranches();

        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->schema([
                    Select::make('period')
                        ->label('Period')
                        ->options(Period::OPTIONS)
                        ->default(Period::DEFAULT)
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->live(),
                    DatePicker::make('from')
                        ->label('From')
                        ->native(false)
                        ->maxDate(today())
                        ->visible(fn (callable $get) => $get('period') === 'custom')
                        ->live(),
                    DatePicker::make('to')
                        ->label('To')
                        ->native(false)
                        ->maxDate(today())
                        ->visible(fn (callable $get) => $get('period') === 'custom')
                        ->live(),
                    Select::make('branch')
                        ->label('Branch')
                        ->options($branches->pluck('name', 'id')->all())
                        ->placeholder('All my branches')
                        ->native(false)
                        ->visible($branches->count() > 1)
                        ->live(),
                ])
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportSales')
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->canAccessArea('bookings.sales'))
                ->action(fn () => $this->exportDailySales()),
        ];
    }

    /**
     * The daily sales table for the chosen period and branch, as a CSV.
     */
    public function exportDailySales(): StreamedResponse
    {
        $insights = Insights::for(auth()->user(), $this->filters);

        abort_unless($insights->can('bookings.sales'), 403);

        $rows = $insights->daily();
        $branch = $insights->branchId ? Str::slug($insights->branches()->first()?->name ?? '') : null;
        $name = '826-daily-sales-'.$insights->period->from->toDateString().'-to-'.$insights->period->to->toDateString().($branch ? "-{$branch}" : '').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Units done', 'Sales', 'Collected', 'Discounts'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, [$row['date'], $row['units'], $row['sales'], $row['collected'], $row['discounts']], escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
