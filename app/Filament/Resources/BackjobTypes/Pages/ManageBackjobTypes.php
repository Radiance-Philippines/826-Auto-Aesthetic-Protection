<?php

namespace App\Filament\Resources\BackjobTypes\Pages;

use App\Filament\Resources\BackjobTypes\BackjobTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBackjobTypes extends ManageRecords
{
    protected static string $resource = BackjobTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a backjob type'),
        ];
    }
}
