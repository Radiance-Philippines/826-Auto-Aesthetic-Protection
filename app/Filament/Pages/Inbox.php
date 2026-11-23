<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RefreshesLive;
use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Branch;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Guest;
use App\Models\User;
use App\Support\Inbox\Inbox as InboxService;
use App\Support\Inbox\InboxException;
use App\Support\Inbox\InboxList;
use App\Support\Inbox\SampleInbox;
use App\Support\InboxAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Every customer's Messenger chat with the branches' Facebook Pages,
 * operations' heads-ups about changed bookings and each branch team's own
 * internal threads, in one place: a tab per branch (and All), a channel
 * filter, a list on the left, the thread and a composer on the right. Staff
 * only see their own branches' threads.
 */
class Inbox extends Page
{
    use RefreshesLive;
    use ShownInItsPanels;

    protected function liveArea(): string
    {
        return 'inbox';
    }

    protected function liveBranch(): ?int
    {
        return ctype_digit($this->branch) ? (int) $this->branch : null;
    }

    protected function liveRefreshed(): void
    {
        $this->refreshInbox();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?string $navigationLabel = 'Inbox';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Inbox';

    protected static ?string $slug = 'inbox';

    protected string $view = 'filament.pages.inbox';

    public const MAX_REPLY_LENGTH = 2000;

    private const PAGE_SIZE = 50;

    /**
     * The open conversation (in the URL, so notification links open it).
     */
    #[Url]
    public ?int $conversation = null;

    /**
     * A branch id, or "all".
     */
    #[Url]
    public string $branch = 'all';

    /**
     * open (the to-do list), unread, done or all.
     */
    #[Url]
    public string $filter = 'open';

    /**
     * all, messenger, headsup or internal.
     */
    #[Url]
    public string $channel = 'all';

    public string $search = '';

    public string $reply = '';

    public int $messageLimit = self::PAGE_SIZE;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && InboxAccess::allows($user);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $unread = $user instanceof User ? Conversation::visibleTo($user)->unread($user)->count() : 0;

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        SampleInbox::ensureSeeded();

        $this->branch = $this->validBranch($this->branch);
        $this->channel = in_array($this->channel, InboxList::CHANNELS, true) ? $this->channel : 'all';

        if ($this->conversation !== null) {
            $this->open($this->conversation);
        }
    }

    /**
     * "All" is for a super admin, or someone with two or more branches (it
     * then only covers their own); one branch has just its own tab.
     */
    public function showsAllTab(): bool
    {
        return InboxList::showsAllTab(auth()->user(), $this->branchTabs);
    }

    private function validBranch(string $branch): string
    {
        return InboxList::validBranch(auth()->user(), $this->branchTabs, $branch);
    }

    /**
     * The branches this user may see: the tabs (after "All").
     *
     * @return SupportCollection<string, Branch>
     */
    #[Computed]
    public function branchTabs(): SupportCollection
    {
        return InboxList::branchTabs(auth()->user());
    }

    /**
     * Unread chats per branch id, for the tab badges.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function unreadByBranch(): array
    {
        return InboxList::unreadByBranch(auth()->user());
    }

    /**
     * Polled while the page is open (no push service here).
     */
    public function updatedBranch(): void
    {
        $this->branch = $this->validBranch($this->branch);
    }

    public function refreshInbox(): void
    {
        unset($this->conversations, $this->activeConversation, $this->messages, $this->unreadByBranch);

        if ($this->activeConversation?->unread_count > 0) {
            app(InboxService::class)->markRead($this->activeConversation, auth()->user());
        }
    }

    public function open(int $conversationId): void
    {
        $conversation = Conversation::visibleTo(auth()->user())->find($conversationId);

        // Another branch's chat (or none): the same 404, never a hint it exists
        abort_unless($conversation && Gate::allows('view', $conversation), 404);

        $this->conversation = $conversation->getKey();
        $this->reply = '';
        $this->messageLimit = self::PAGE_SIZE;
        unset($this->activeConversation, $this->messages, $this->conversations, $this->unreadByBranch);

        app(InboxService::class)->markRead($conversation, auth()->user());
    }

    public function close(): void
    {
        $this->conversation = null;
        unset($this->activeConversation, $this->messages);
    }

    public function loadOlder(): void
    {
        $this->messageLimit += self::PAGE_SIZE;
        unset($this->messages);
    }

    public function send(): void
    {
        $conversation = $this->conversationToUpdate();

        if (! $conversation) {
            return;
        }

        $this->validate(['reply' => ['required', 'string', 'max:'.self::MAX_REPLY_LENGTH]], attributes: ['reply' => 'message']);

        $user = auth()->user();

        try {
            app(InboxService::class)->reply($conversation, $this->reply, $user instanceof User ? $user : null);
        } catch (InboxException $exception) {
            Notification::make()->danger()->title('Message not sent')->body($exception->getMessage())->persistent()->send();
            unset($this->messages);

            return;
        }

        if (! $conversation->isTeamOnly() && InboxService::sendsNothing($conversation)) {
            Notification::make()->warning()->title('Saved here only')->body('This is a demo: nothing was sent to Messenger.')->send();
        }

        $this->reply = '';
        unset($this->messages, $this->conversations, $this->activeConversation, $this->unreadByBranch);
    }

    public function toggleDone(): void
    {
        $conversation = $this->conversationToUpdate();

        if (! $conversation) {
            return;
        }

        $conversation->forceFill(['status' => $conversation->isDone() ? Conversation::OPEN : Conversation::DONE])->save();
        unset($this->conversations, $this->activeConversation);
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return InboxList::query(auth()->user(), $this->branchTabs, $this->branch, $this->filter, $this->search, $this->channel)
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function activeConversation(): ?Conversation
    {
        return $this->conversation
            ? Conversation::visibleTo(auth()->user())->with(['branch', 'guest', 'assignee', 'booking', 'linkedBooking'])->find($this->conversation)
            : null;
    }

    /**
     * The latest messages of the open conversation, oldest first.
     *
     * @return Collection<int, ConversationMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        if (! $this->activeConversation) {
            return new Collection;
        }

        return $this->activeConversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($this->messageLimit + 1)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * The open chat, once the policy agrees this user may change it (a
     * forged conversation id from another branch is a 404, never touched).
     */
    private function conversationToUpdate(): ?Conversation
    {
        $conversation = $this->activeConversation;

        if ($conversation === null) {
            abort_if($this->conversation !== null, 404);

            return null;
        }

        Gate::authorize('update', $conversation);

        return $conversation;
    }

    public function hasOlderMessages(): bool
    {
        return $this->messages->count() > $this->messageLimit;
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return ['open' => 'Open', 'unread' => 'Unread', 'done' => 'Done', 'all' => 'All'];
    }

    /**
     * @return array<string, string>
     */
    public function channels(): array
    {
        return ['all' => 'All', Conversation::MESSENGER => 'Messenger', Conversation::HEADSUP => 'Heads-up', Conversation::INTERNAL => 'Internal'];
    }

    /**
     * The booking a heads-up or internal thread is about, when this user may open it.
     */
    public function bookingUrl(Conversation $conversation): ?string
    {
        $user = auth()->user();
        $booking = $conversation->aboutBooking();

        return $booking && $user instanceof User && $user->canAccessArea('bookings.view')
            ? BookingResource::getUrl('view', ['record' => $booking])
            : null;
    }

    /**
     * Past Meta's standard 24-hour window since the customer's last message:
     * a hint only, Meta decides whether it accepts the reply.
     */
    public function isPastReplyWindow(): bool
    {
        $last = $this->activeConversation?->last_inbound_at;

        return $last !== null && $last->lt(now()->subHours(InboxService::WINDOW_HOURS));
    }

    /**
     * What to tell the team above the list: sample chats, or Meta not set up.
     */
    public function setupNotice(): ?string
    {
        return InboxList::setupNotice(auth()->user(), $this->branchTabs, $this->branch);
    }

    protected function getHeaderActions(): array
    {
        return [$this->startThreadAction()];
    }

    /**
     * "New team thread": a subject and first message for one of the user's
     * branches, optionally about a booking. Stays inside the system.
     */
    public function startThreadAction(): Action
    {
        return Action::make('startThread')
            ->label('New team thread')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->slideOver()
            ->modalHeading('New team thread')
            ->modalDescription('Only your branch’s team sees this. It is never sent to a customer or to Messenger.')
            ->modalSubmitActionLabel('Start thread')
            ->modalSubmitAction(fn (Action $action): Action => $action->icon(Heroicon::OutlinedPaperAirplane))
            ->fillForm(fn (): array => ['branch_id' => ctype_digit($this->branch) ? (int) $this->branch : ($this->branchTabs->count() === 1 ? (int) $this->branchTabs->keys()->first() : null)])
            ->schema([
                Select::make('branch_id')
                    ->label('Branch')
                    ->options(fn (): array => $this->branchTabs->map(fn (Branch $branch): string => $branch->name)->all())
                    ->required(),
                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(120),
                Textarea::make('body')
                    ->label('Message')
                    ->required()
                    ->rows(5)
                    ->maxLength(self::MAX_REPLY_LENGTH),
                TextInput::make('booking_reference')
                    ->label('Booking reference (optional)')
                    ->helperText('Links the thread to a booking of that branch, like 826-2YD8F.')
                    ->maxLength(40),
            ])
            ->action(function (array $data, Action $action): void {
                $user = auth()->user();

                try {
                    $conversation = app(InboxService::class)->startInternal($user, (int) $data['branch_id'], (string) $data['subject'], (string) $data['body'], $data['booking_reference'] ?? null);
                } catch (InboxException $exception) {
                    Notification::make()->danger()->title('Thread not started')->body($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                $this->channel = 'internal';
                $this->filter = 'open';
                $this->open($conversation->getKey());
            });
    }

    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label(fn (): string => $this->activeConversation?->assignee ? 'Assigned: '.$this->activeConversation->assignee->name : 'Assign')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->size('sm')
            ->modalHeading('Assign this chat')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => ['assigned_to' => $this->activeConversation?->assigned_to])
            ->schema([
                Select::make('assigned_to')
                    ->label('Team member')
                    ->placeholder('Nobody')
                    ->options(fn (): array => $this->assignableUsers())
                    ->searchable(),
            ])
            ->action(function (array $data): void {
                $conversation = $this->conversationToUpdate();
                $userId = filled($data['assigned_to'] ?? null) ? (int) $data['assigned_to'] : null;

                if (! $conversation || ($userId !== null && ! array_key_exists($userId, $this->assignableUsers()))) {
                    return;
                }

                $conversation->forceFill(['assigned_to' => $userId])->save();
                unset($this->activeConversation);
            });
    }

    /**
     * The open chat's branch staff who may use the inbox (never another branch's people).
     *
     * @return array<int, string>
     */
    private function assignableUsers(): array
    {
        $branchId = $this->activeConversation?->branch_id;

        return User::query()
            ->whereHas('branches', fn ($branches) => $branches->whereKey($branchId))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => InboxAccess::allows($user))
            ->pluck('name', 'id')
            ->all();
    }

    public function linkCustomerAction(): Action
    {
        return Action::make('linkCustomer')
            ->hidden(fn (): bool => (bool) $this->activeConversation?->isTeamOnly())
            ->label(fn (): string => $this->activeConversation?->guest_id ? 'Change customer' : 'Link customer')
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('gray')
            ->size('sm')
            ->modalHeading('Link this chat to a customer')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => ['guest_id' => $this->activeConversation?->guest_id])
            ->schema([
                Select::make('guest_id')
                    ->label('Customer')
                    ->placeholder('No customer')
                    ->helperText('Search by name or phone number.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->guestQuery()
                        ->where(fn ($query) => $query->where('guests.name', 'like', "%{$search}%")->orWhere('guests.phone', 'like', "%{$search}%"))
                        ->orderBy('guests.name')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Guest $guest) => [$guest->getKey() => $this->guestLabel($guest)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($guest = $this->guestQuery()->find($value)) ? $this->guestLabel($guest) : null),
            ])
            ->action(function (array $data): void {
                $conversation = $this->conversationToUpdate();
                $guestId = filled($data['guest_id'] ?? null) ? (int) $data['guest_id'] : null;

                if (! $conversation || ($guestId !== null && ! $this->guestQuery()->whereKey($guestId)->exists())) {
                    return;
                }

                $conversation->forceFill(['guest_id' => $guestId])->save();
                unset($this->activeConversation);
            });
    }

    /**
     * Customers this team member may open (their branches' customers).
     *
     * @return Builder<Guest>
     */
    private function guestQuery(): Builder
    {
        return Guest::query()->visibleTo(auth()->user());
    }

    private function guestLabel(Guest $guest): string
    {
        return collect([$guest->name, $guest->phone])->filter()->implode(' · ');
    }

    public function customerUrl(Guest $guest): string
    {
        return CustomerResource::getUrl('view', ['record' => $guest]);
    }
}
