<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\Dashboard\Insights;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * For the dashboard's widgets: the page's period and branch filters, turned
 * into the numbers this signed-in user may see.
 */
trait UsesInsights
{
    use InteractsWithPageFilters;

    protected ?Insights $insightsCache = null;

    protected function insights(): Insights
    {
        return $this->insightsCache ??= Insights::for(auth()->user(), $this->pageFilters);
    }
}
