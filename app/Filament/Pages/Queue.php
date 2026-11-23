<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RefreshesLive;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\BranchBlocks\BranchBlockResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Support\Reservations;
use App\Support\Schedule\Availability;
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
        $this->branch ??= array_key_first($branches);

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
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) ? CarbonImmutable::parse($this->date) : CarbonImmutable::today();

        return $date->startOfDay();
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
        $cap = $branch->dailyCap();
        $units = Availability::unitsOn($branch, $day);
        $state = $cap > 0 ? Availability::check($branch, $day, byStaff: true) : 'closed';

        $bookings = Booking::query()
            ->where('branch_id', $branch->id)
            ->where(fn ($board) => $board
                ->whereIn('status', Booking::IN_SHOP)
                ->orWhere(fn ($booked) => $booked
                    ->where('drop_off_on', $day->toDateString())
                    ->where(fn ($status) => $status
                        ->whereIn('status', ['confirmed', 'payment_review'])
                        ->orWhere(fn ($hold) => $hold->where('status', 'awaiting_payment')->where('hold_expires_at', '>', now())))))
            ->with('items')
            ->orderBy('drop_off_on')
            ->orderBy('id')
            ->get();

        $columns = [];

        foreach (self::COLUMNS as $status => $column) {
            $columns[$status] = [
                ...$column,
                'cards' => $bookings
                    ->filter(fn (Booking $booking) => $status === 'confirmed'
                        ? in_array($booking->status, ['confirmed', 'payment_review', 'awaiting_payment'], true)
                        : $booking->status === $status)
                    ->map(fn (Booking $booking) => $this->card($booking))
                    ->values()
                    ->all(),
            ];
        }

        $arrivals = Booking::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'confirmed')
            ->where('drop_off_on', today()->toDateString())
            ->with('items')
            ->orderBy('id')
            ->get()
            ->map(fn (Booking $booking) => $this->card($booking))
            ->all();

        return [
            'shop' => $branch,
            'branches' => $this->branchOptions(),
            'heading' => $day->format('l, M j, Y'),
            'isToday' => $day->isToday(),
            'cap' => $cap,
            'units' => $units,
            'state' => $state,
            'columns' => $columns,
            'arrivals' => $arrivals,
            'sizeReviews' => Booking::query()->where('branch_id', $branch->id)->needsSizeReview()->count(),
            'toSchedule' => Booking::query()->where('branch_id', $branch->id)->where('kind', 'home')->where('status', 'requested')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'name' => $booking->name,
            'unit' => $booking->vehicleLabel() ?: '—',
            'services' => $booking->servicesLabel(),
            'readyOn' => $booking->ready_on?->format('D, M j'),
            'home' => $booking->isHome(),
            'needsSize' => $booking->needsSizeReview(),
            'note' => match ($booking->stage()) {
                'awaiting_payment' => 'Waiting for the fee',
                'payment_review' => 'Screenshot to check',
                default => null,
            },
            'canAdvance' => $booking->nextStatus() !== null && (bool) auth()->user()?->canAccessArea('queue.update'),
            'button' => $booking->nextStatus() ? 'Mark '.strtolower(Booking::STATUSES[$booking->nextStatus()]) : null,
            'url' => BookingResource::getUrl('view', ['record' => $booking]),
        ];
    }
}
