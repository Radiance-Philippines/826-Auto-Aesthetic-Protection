<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesInsights;
use App\Support\Dashboard\Insights;
use Filament\Widgets\Widget;

/**
 * A dashboard card made of small tables and number rows, each with one
 * line saying what it counts. Subclasses return the blocks:
 *
 *  - table: ['title', 'help', 'columns' => list<string>, 'rows' => list<list<string>>, 'empty' => string]
 *  - stats: ['title', 'help', 'items' => list<array{label: string, value: string, help: string, url?: string|null}>]
 */
abstract class InsightWidget extends Widget
{
    use UsesInsights;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.insight';

    protected int|string|array $columnSpan = 'full';

    protected string $heading = '';

    /**
     * @return list<array<string, mixed>>
     */
    abstract protected function blocks(Insights $insights): array;

    protected function description(Insights $insights): ?string
    {
        return $insights->period->label();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $insights = $this->insights();

        return [
            'heading' => $this->heading,
            'description' => $this->description($insights),
            'blocks' => $this->blocks($insights),
        ];
    }
}
