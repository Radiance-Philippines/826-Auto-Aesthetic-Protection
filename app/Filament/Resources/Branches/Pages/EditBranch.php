<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\Branch;
use App\Support\BranchForm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return BranchForm::fillHours($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return BranchForm::saveHours($data);
    }

    protected function afterSave(): void
    {
        /** @var Branch $branch */
        $branch = $this->getRecord()->refresh();

        foreach (BranchForm::warnings($branch) as $warning) {
            Notification::make()
                ->warning()
                ->title($warning['title'])
                ->body($warning['body'])
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')
                ->label('View on the site')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (Branch $record) => url("/branches/{$record->slug}"), shouldOpenInNewTab: true)
                ->visible(fn (Branch $record) => $record->status !== 'draft'),
            DeleteAction::make(),
        ];
    }
}
