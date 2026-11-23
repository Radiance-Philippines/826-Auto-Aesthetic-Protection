<?php

namespace App\Filament\Resources\AppReleases\Pages;

use App\Filament\Resources\AppReleases\AppReleaseResource;
use App\Models\AppRelease;
use Filament\Resources\Pages\CreateRecord;

class CreateAppRelease extends CreateRecord
{
    protected static string $resource = AppReleaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AppRelease::fingerprint($data);
    }
}
