<?php

namespace App\Filament\Resources\AppReleases\Pages;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Filament\Resources\AppReleases\AppReleaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppReleases extends ListRecords
{
    use BlockedWhileImpersonating;

    protected static string $resource = AppReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New release')];
    }
}
