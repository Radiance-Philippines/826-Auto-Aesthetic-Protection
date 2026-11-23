<?php

namespace App\Filament\Resources\AppReleases\Pages;

use App\Filament\Resources\AppReleases\AppReleaseResource;
use App\Models\AppRelease;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAppRelease extends EditRecord
{
    protected static string $resource = AppReleaseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AppRelease::fingerprint($data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
