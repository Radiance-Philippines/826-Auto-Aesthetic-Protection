<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Branch;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Access;
use App\Support\Invitations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;
use UnitEnum;

/**
 * Who can sign in to the admin (super admins only). Team members are
 * invited by email with a role, choose their own password, and see only the
 * branches they're assigned to; super admins see every branch. A role has
 * default areas of work; an account can be given its own list instead.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Users';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Super admins only: handing out roles is how someone becomes one.
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isOwner();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    /**
     * Not yourself, and never the last super admin.
     */
    public static function canDelete(Model $record): bool
    {
        return self::canViewAny()
            && ! $record->is(auth()->user())
            && ! ($record->isOwner() && User::where('role', Access::SUPER_ADMIN)->count() <= 1);
    }

    /**
     * The areas that can be ticked for one account: all but Users, which
     * stays with super admins.
     *
     * @return array<string, string>
     */
    public static function areaOptions(): array
    {
        return Arr::except(Access::AREAS, ['users']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(190)
                        ->unique(ignoreRecord: true),
                    Select::make('role')
                        ->options(Access::ROLES)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('access', Access::defaults($state)))
                        ->helperText('A super admin sees every branch and everything. The others work with their own branches only.')
                        // You can't demote yourself (and lock everyone out of this page)
                        ->disabled(fn (?User $record) => $record?->is(auth()->user())),
                    CheckboxList::make('access')
                        ->label('Extra access')
                        ->options(self::areaOptions())
                        ->helperText('Ticked is what this role does by default. Tick or untick to give this account its own list; change the role to start over.')
                        ->columns(2)
                        ->visible(fn (Get $get) => $get('role') !== Access::SUPER_ADMIN)
                        ->columnSpanFull(),
                    CheckboxList::make('branches')
                        ->label('Branches')
                        ->relationship('branches', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                        ->helperText('The bookings, queue and closures they work with.')
                        ->columns(2)
                        ->visible(fn (Get $get) => $get('role') !== Access::SUPER_ADMIN)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('branches')->withCount('passkeys'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Access::ROLES[$state] ?? $state)
                    ->color(fn (string $state) => $state === Access::SUPER_ADMIN ? 'primary' : 'gray')
                    ->description(fn (User $record) => ($record->isOwner() ? 'Every branch' : ($record->branches->pluck('name')->implode(', ') ?: 'No branch yet')).(is_array($record->permissions) ? ' · own access list' : '')),
                TextColumn::make('two_factor')
                    ->label('2FA')
                    ->state(fn (User $record) => $record->hasTwoFactor() ? 'On' : 'Off')
                    ->badge()
                    ->color(fn (string $state) => $state === 'On' ? 'success' : 'gray'),
                TextColumn::make('passkeys_count')
                    ->label('Passkeys')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->state(fn (User $record) => $record->isPendingInvite() ? 'Invite sent' : 'Active')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'warning')
                    ->description(fn (User $record) => $record->isPendingInvite() ? $record->invited_at->diffForHumans() : null),
            ])
            ->recordActions([
                self::resendInviteAction(),
                self::resetTwoFactorAction(),
                self::removePasskeysAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('Invite team member')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalDescription('They get an email with a link to choose their password. Everyone but a super admin sees only the branches you pick.')
            ->modalSubmitActionLabel('Send invite')
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(190)
                    ->unique(User::class, 'email'),
                Select::make('role')
                    ->options(Access::ROLES)
                    ->default(Access::BRANCH_MANAGER)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live(),
                CheckboxList::make('branches')
                    ->label('Branches')
                    ->options(fn () => Branch::query()->ordered()->pluck('name', 'id')->all())
                    ->required(fn (Get $get) => $get('role') !== Access::SUPER_ADMIN)
                    ->visible(fn (Get $get) => $get('role') !== Access::SUPER_ADMIN)
                    ->columns(2),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $user = app(Invitations::class)->invite($data['name'], $data['email'], auth()->user(), $data['role'] ?? Access::BRANCH_MANAGER);
                    $user->branches()->sync(array_map('intval', $data['branches'] ?? []));
                } catch (Throwable $e) {
                    Notification::make()->danger()->persistent()->title('Invite not sent')->body($e->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title("Invite sent to {$user->email}")->send();
            });
    }

    /**
     * For someone locked out of their authenticator: clears the app code, the
     * recovery codes and the email code, so they can set it up again.
     */
    public static function resetTwoFactorAction(): Action
    {
        return Action::make('resetTwoFactor')
            ->label('Reset 2FA')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->authorize(fn () => self::canViewAny())
            ->visible(fn (User $record) => $record->hasTwoFactor())
            ->requiresConfirmation()
            ->modalDescription('Clears their authenticator app, recovery codes and email code. They sign in with just their password until they set it up again.')
            ->action(function (User $record) {
                $record->resetTwoFactor();

                Notification::make()->success()->title("2FA reset for {$record->name}")->send();
            });
    }

    /**
     * For a lost or stolen device: removes every passkey of that person (and
     * says so in the security activity), so they sign in with their password
     * and add new ones.
     */
    public static function removePasskeysAction(): Action
    {
        return Action::make('removePasskeys')
            ->label('Remove passkeys')
            ->icon(Heroicon::OutlinedFingerPrint)
            ->color('gray')
            ->authorize(fn () => self::canViewAny())
            ->visible(fn (User $record) => $record->passkeys()->exists())
            ->requiresConfirmation()
            ->modalDescription('Removes every passkey of this person, for a lost or stolen device. They sign in with their password (and 2FA) until they add a passkey again.')
            ->action(function (User $record) {
                $count = $record->passkeys()->count();
                $record->passkeys()->delete();
                SecurityEvent::record(SecurityEvent::PASSKEYS_CLEARED, $record, $count === 1 ? '1 passkey' : "{$count} passkeys", auth()->user());

                Notification::make()->success()->title("Passkeys removed for {$record->name}")->send();
            });
    }

    public static function resendInviteAction(): Action
    {
        return Action::make('resendInvite')
            ->label('Resend invite')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn (User $record) => $record->isPendingInvite())
            ->requiresConfirmation()
            ->modalDescription('Emails a new link. The old one stops working.')
            ->action(function (User $record) {
                try {
                    app(Invitations::class)->send($record, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()->danger()->persistent()->title('Invite not sent')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("New invite sent to {$record->email}")->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
