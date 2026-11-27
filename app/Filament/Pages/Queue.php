<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RefreshesLive;
use App\Filament\Concerns\ShownInItsPanels;
use App\Filament\Resources\Bookings\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\BranchBlocks\BranchBlockResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Support\CurrentBranch;
use App\Support\QueueBoard;
use App\Support\Reservations;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One branch's queue: the units booked for a day, and the ones in the
 * shop, moving left to right (booked, arrived, in progress, quality check,
 * ready) until they exit. A card's button moves it to the next step; the
 * arrival and exit times are recorded. The strip on top says how many units
 * drop off that day against the daily cap.
 */
class Queue extends Page
{
    use RefreshesLive;
    use ShownInItsPanels;

    protected function liveArea(): string
    {
        return 'queue';
    }

    protected function liveBranch(): ?int
    {
        return $this->branch;
    }

    /**
     * The board's columns: the status each holds, and the icon of the
     * button that moves a card out of it (its label names the next status).
     *
     * @var array<string, array{label: string, icon: string}>
     */
    public const COLUMNS = [
        'confirmed' => ['label' => 'Booked', 'icon' => 'heroicon-m-arrow-right-end-on-rectangle'],
        'arrived' => ['label' => 'Arrived', 'icon' => 'heroicon-m-wrench-screwdriver'],
        'in_progress' => ['label' => 'In progress', 'icon' => 'heroicon-m-magnifying-glass'],
        'quality_check' => ['label' => 'Quality check', 'icon' => 'heroicon-m-check-badge'],
        'ready' => ['label' => 'Ready', 'icon' => 'heroicon-m-check-circle'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Queue';

    protected static ?string $slug = 'queue';

    protected string $view = 'filament.pages.queue';

    #[Url]
    public ?int $branch = null;

    #[Url]
    public string $date = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAccessArea('bookings.view');
    }

    public function mount(): void
    {
        $branches = $this->branchOptions();
        // The top selector wins over a ?branch= left in the address
        $this->branch = CurrentBranch::for(auth()->user()) ?? $this->branch ?? array_key_first($branches);

        abort_unless($this->branch === null || auth()->user()->canManageBranch($this->branch), 403);

        $this->date = $this->day()->toDateString();
    }

    /**
     * The branch picker: Filament's own select, never the browser's.
     */
    public function branchForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch')
                ->hiddenLabel()
                ->options(fn () => $this->branchOptions())
                ->native(false)
                ->selectablePlaceholder(false)
                ->live(),
        ]);
    }

    public function updatedBranch(): void
    {
        abort_unless($this->branch === null || auth()->user()->canManageBranch($this->branch), 403);
    }

    public function previous(): void
    {
        $this->date = $this->day()->subDay()->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->day()->addDay()->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    /**
     * Moves a card to its next step.
     */
    public function advance(int $booking): void
    {
        $record = Booking::query()->visibleTo(auth()->user())->where('branch_id', $this->branch)->findOrFail($booking);

        abort_unless(auth()->user()->canAccessArea('queue.update'), 403);

        if ($record->nextStatus() === null) {
            return;
        }

        Reservations::advance($record);

        Notification::make()->success()->title("{$record->name}: ".Booking::STATUSES[$record->status])->send();
    }

    /**
     * A card's "Assign": who is on the unit, one of this branch's.
     */
    public function assignedToAction(): Action
    {
        return BookingActions::assignedTo()
            ->record(fn (array $arguments) => Booking::query()->visibleTo(auth()->user())->where('branch_id', $this->branch)->findOrFail($arguments['booking'] ?? 0));
    }

    /**
     * @return array<int, string>
     */
    public function branchOptions(): array
    {
        return Branch::query()->visibleTo(auth()->user())->ordered()->pluck('name', 'id')->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newBooking')
                ->label('Add a booking')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => BookingResource::canCreate())
                ->url(fn () => BookingResource::getUrl('create')),
            Action::make('closures')
                ->label('Closures and full days')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->visible(fn () => BranchBlockResource::canViewAny())
                ->url(fn () => BranchBlockResource::getUrl('index')),
        ];
    }

    private function day(): CarbonImmutable
    {
        return QueueBoard::day($this->date);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $branch = $this->branch ? Branch::query()->visibleTo(auth()->user())->find($this->branch) : null;

        if (! $branch) {
            return ['shop' => null, 'branches' => $this->branchOptions()];
        }

        $day = $this->day();
        $dropOffs = QueueBoard::dropOffs($branch, $day);
        $bookings = QueueBoard::bookings($branch, $day);

        $columns = [];

        foreach (self::COLUMNS as $status => $column) {
            $columns[$status] = [
                ...$column,
                'cards' => $bookings
                    ->filter(fn (Booking $booking) => QueueBoard::column($booking) === $status)
                    ->map(fn (Booking $booking) => $this->card($booking))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'shop' => $branch,
            'branches' => $this->branchOptions(),
            'heading' => $day->format('l, M j, Y'),
            'isToday' => $day->isToday(),
            ...$dropOffs,
            'columns' => $columns,
            'arrivals' => QueueBoard::arrivals($branch)->map(fn (Booking $booking) => $this->card($booking))->all(),
            'sizeReviews' => QueueBoard::sizeReviews($branch),
            'toSchedule' => QueueBoard::homeRequests($branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Booking $booking): array
    {
        return [
            ...QueueBoard::card($booking, auth()->user()),
            'url' => BookingResource::getUrl('view', ['record' => $booking]),
        ];
    }
}
