<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\TeamAccounts;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    use BlockedWhileImpersonating;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::viewAsAction(),
            UserResource::resetTwoFactorAction(),
            UserResource::removePasskeysAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();
        $data['access'] = $user->areas();

        return $data;
    }

    /**
     * The extra-access ticks are stored only when they differ from the role's default.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();
        $role = $data['role'] ?? $user->role;

        if (TeamAccounts::wouldRemoveLastSuperAdmin($user, $role)) {
            Notification::make()->danger()->title('Not saved')->body('There must be at least one super admin.')->send();

            throw ValidationException::withMessages(['data.role' => 'There must be at least one super admin.']);
        }

        $data['permissions'] = TeamAccounts::ownPermissions($role, $data['access'] ?? []);
        unset($data['access']);

        return $data;
    }
}
