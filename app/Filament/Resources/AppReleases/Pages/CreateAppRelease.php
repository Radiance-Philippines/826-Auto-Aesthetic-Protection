<?php

namespace App\Filament\Resources\AppReleases\Pages;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Filament\Resources\AppReleases\AppReleaseResource;
use App\Models\AppRelease;
use Filament\Resources\Pages\CreateRecord;

class CreateAppRelease extends CreateRecord
{
    use BlockedWhileImpersonating;

    protected static string $resource = AppReleaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AppRelease::fingerprint($data);
    }
}
