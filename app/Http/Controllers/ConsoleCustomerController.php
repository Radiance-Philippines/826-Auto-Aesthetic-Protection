<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Inbox;
use App\Filament\Pages\Warranty;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\ReferralRedemption;
use App\Models\ReferralSetting;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\BookingDesk;
use App\Support\CustomerDesk;
use App\Support\Rewards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The React admin's Customers: the Filament resource's list, view (with its
 * four relation lists), create and edit as JSON, under GuestPolicy and the
 * person's branches.
 */
class ConsoleCustomerController extends StaffController
{
    private const PER_PAGE = 25;

    /**
     * GET ?search=&branch=&salesRep=&source=&sort=name&desc=1&page=
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Guest::class);

        $user = $this->user();
        $branches = BookingDesk::branchOptions($user);
        $reps = CustomerDesk::salesRepOptions();
        $query = Guest::query()
            ->visibleTo($user)
            ->with(['branch', 'salesRep'])
            ->withCount(['vehicles', 'bookings'])
            ->withMax(['bookings as last_visit_on' => fn (Builder $bookings) => $bookings->whereIn('status', ['arrived', 'in_progress', 'quality_check', 'ready', 'exited'])], 'drop_off_on');

        $branch = (int) $request->query('branch');
        $rep = (int) $request->query('salesRep');
        $source = (string) $request->query('source');
        $query->when(isset($branches[$branch]), fn (Builder $guests) => $guests->where('guests.branch_id', $branch))
            ->when(isset($reps[$rep]), fn (Builder $guests) => $guests->where('sales_rep_id', $rep))
            ->when(array_key_exists($source, Guest::SOURCES), fn (Builder $guests) => $guests->where('source', $source));

        foreach (preg_split('/\s+/', trim((string) $request->query('search')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $plate = Booking::normalizePlate($word);
            $query->where(fn (Builder $match) => $match
                ->where('guests.name', 'like', "%{$word}%")
                ->orWhere('guests.phone', 'like', "%{$word}%")
                ->orWhere('guests.email', 'like', "%{$word}%")
                ->when($plate !== '', fn (Builder $or) => $or->orWhereHas('vehicles', fn (Builder $vehicles) => $vehicles->where('plate_number', 'like', "%{$plate}%"))));
        }

        $page = $query->orderBy('name', $request->query('sort') === 'name' && $request->boolean('desc') ? 'desc' : 'asc')->orderBy('guests.id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Guest $guest) => [
                'id' => $guest->getKey(),
                'name' => $guest->name,
                'contact' => collect([$guest->phone, $guest->email])->filter()->implode(' · '),
                'branch' => $guest->branch?->name,
                'salesRep' => $guest->salesRep?->name,
                'units' => (int) $guest->vehicles_count,
                'bookings' => (int) $guest->bookings_count,
                'lastVisit' => $guest->last_visit_on ? date('M j, Y', strtotime((string) $guest->last_visit_on)) : null,
                'referralCode' => $guest->referral_code,
            ])->all(),
            'meta' => ['total' => $page->total(), 'perPage' => $page->perPage()],
            'filters' => [
                'branches' => count($branches) > 1 ? $this->options($branches) : [],
                'salesReps' => $this->options($reps),
                'sources' => $this->options(Guest::SOURCES),
            ],
            'can' => ['create' => Gate::allows('create', Guest::class)],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * What the create and edit forms offer.
     */
    public function form(): JsonResponse
    {
        Gate::authorize('viewAny', Guest::class);

        return response()->json([
            'branches' => $this->options(BookingDesk::branchOptions($this->user())),
            'salesReps' => $this->options(CustomerDesk::salesRepOptions()),
            'can' => ['create' => Gate::allows('create', Guest::class)],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * A walk-in or phone customer the team adds by hand.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Guest::class);

        $guest = Guest::create([...$this->validated($request), 'source' => 'walk_in']);

        return response()->json(['id' => $guest->getKey()], 201);
    }

    public function show(int $id): JsonResponse
    {
        $guest = $this->guest($id, 'view');
        $user = $this->user();
        $guest->load(['branch', 'salesRep']);
        $balance = $guest->pointsBalance();
        $redeems = $user->canAccessArea('customers.write') && $user->canAccessArea('bookings.sales');

        return response()->json([
            'id' => $guest->getKey(),
            'name' => $guest->name,
            'phone' => $guest->phone,
            'phoneUrl' => BookingDesk::phoneUrl($guest->phone),
            'email' => $guest->email,
            'branch' => $guest->branch?->name,
            'salesRep' => $guest->salesRep?->name,
            'source' => Guest::SOURCES[$guest->source] ?? $guest->source,
            'notes' => $guest->notes,
            'referral' => [
                'code' => $guest->referral_code,
                'timesUsed' => $guest->referralRedemptions()->whereNull('voided_at')->count(),
                'points' => $balance,
                'pointsNote' => CustomerDesk::pointsNote($guest),
            ],
            'warranty' => CustomerDesk::warrantyLine($guest, $user),
            'can' => [
                'edit' => Gate::allows('update', $guest),
                'delete' => Gate::allows('delete', $guest),
                'warranty' => Warranty::canAccess(),
            ],
            'chatId' => Inbox::canAccess() ? CustomerDesk::chatId($guest, $user) : null,
            'redeem' => $redeems ? [
                'balance' => $balance,
                'description' => CustomerDesk::redeemDescription(),
                // Null: a point has no peso value yet, so there is no booking to take it off
                'bookings' => ReferralSetting::current()->peso_per_point ? $this->options(CustomerDesk::redeemableBookings($guest, $user)) : null,
            ] : null,
            'units' => $this->units($guest),
            'setSize' => $user->canAccessArea('bookings.sales') ? $this->options(VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all()) : null,
            'bookings' => $user->canAccessArea('bookings.view') ? $this->bookings($guest) : null,
            'referrals' => $this->referrals($guest),
            'ledger' => Rewards::ledger($guest)->take(200)->map(fn (array $line, string $key) => [
                'id' => $key,
                'date' => $line['date'],
                'what' => $line['line'],
                'points' => $line['points'],
                'pesos' => $line['pesos'] ? '₱'.number_format($line['pesos']) : null,
                'by' => $line['by'],
            ])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function edit(int $id): JsonResponse
    {
        $guest = $this->guest($id, 'update');

        return response()->json([
            'id' => $guest->getKey(),
            'name' => $guest->name,
            'values' => [
                'name' => $guest->name,
                'phone' => $guest->phone,
                'email' => $guest->email,
                'branchId' => $guest->branch_id,
                'salesRepId' => $guest->sales_rep_id,
                'notes' => $guest->notes,
            ],
            // A home branch that is not the editor's stays as it is
            'branch' => $guest->branch ? ['value' => (string) $guest->branch_id, 'label' => $guest->branch->name] : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $guest = $this->guest($id, 'update');
        $guest->update($this->validated($request, $guest));

        return response()->json(['id' => $guest->getKey()]);
    }

    public function destroy(int $id): Response
    {
        $guest = $this->guest($id, 'delete');

        if ($reason = CustomerDesk::keepReason($guest)) {
            throw ValidationException::withMessages(['customer' => $reason]);
        }

        $guest->delete();

        return response()->noContent();
    }

    /**
     * POST {id}/redeem {points, bookingId, note}
     */
    public function redeem(Request $request, int $id): JsonResponse
    {
        $this->requireArea('customers.write');
        $this->requireArea('bookings.sales');

        $guest = $this->guest($id, 'view');
        $pesos = ReferralSetting::current()->peso_per_point;
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:'.max(1, $guest->pointsBalance())],
            'bookingId' => ['nullable', Rule::in(array_keys(CustomerDesk::redeemableBookings($guest, $this->user())))],
            'note' => ['nullable', 'string', 'max:190'],
        ], [
            'points.required' => 'Say how many points.',
            'points.min' => 'Enter at least 1 point.',
            'points.max' => "They only have {$guest->pointsBalance()} points.",
            'bookingId.in' => 'Pick one of this customer’s open bookings.',
            'note.max' => 'Keep the note under 190 characters.',
        ]);

        $booking = $pesos && filled($data['bookingId'] ?? null) ? Booking::query()->visibleTo($this->user())->findOrFail($data['bookingId']) : null;

        try {
            $redemption = Rewards::redeem($guest, $this->user(), (int) $data['points'], $booking, $data['note'] ?? null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $field) => [$field === 'booking_id' ? 'bookingId' : $field => $messages])->all());
        }

        return response()->json(['points' => $redemption->points, 'off' => $redemption->peso_value ? '₱'.number_format($redemption->peso_value).' off the booking' : null], 201);
    }

    /**
     * A unit's service history, at the branches this person works at.
     */
    public function history(int $id, int $unit): JsonResponse
    {
        return response()
            ->json(['data' => CustomerDesk::unitJobs($this->unit($this->guest($id, 'view'), $unit), $this->user())])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * POST {id}/units/{unit}/size {vehicleSizeId}
     */
    public function size(Request $request, int $id, int $unit): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $vehicle = $this->unit($this->guest($id, 'view'), $unit);
        $data = $request->validate(
            ['vehicleSizeId' => ['required', Rule::in(VehicleSize::query()->active()->pluck('id')->all())]],
            ['vehicleSizeId.required' => 'Pick the size class.', 'vehicleSizeId.in' => 'Pick one of the size classes.'],
        );
        $size = VehicleSize::query()->findOrFail($data['vehicleSizeId']);

        return response()->json(['size' => $size->name, 'priced' => CustomerDesk::setUnitSize($vehicle, $size)]);
    }

    /**
     * A customer of this person's branches (404 otherwise) that they may $ability.
     */
    private function guest(int $id, string $ability): Guest
    {
        $guest = Guest::query()->visibleTo($this->user())->find($id);
        abort_unless($guest, 404, 'Customer not found.');
        Gate::authorize($ability, $guest);

        return $guest;
    }

    private function unit(Guest $guest, int $id): Vehicle
    {
        $unit = $guest->vehicles()->find($id);
        abort_unless($unit, 404, 'Unit not found.');

        return $unit;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Guest $guest = null): array
    {
        $branches = array_keys(BookingDesk::branchOptions($this->user()));

        if ($guest?->branch_id) {
            $branches[] = $guest->branch_id;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:'.ConsoleBookingController::PHONE],
            'email' => ['nullable', 'email', 'max:190'],
            'branchId' => ['nullable', Rule::in($branches)],
            'salesRepId' => ['nullable', Rule::in(array_keys(CustomerDesk::salesRepOptions()))],
            'notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'name.required' => 'Add the customer’s name.',
            'phone.regex' => 'Use numbers only, like 0917 123 4567.',
            'branchId.in' => 'Pick one of your branches.',
            'salesRepId.in' => 'Pick someone from the list.',
            'notes.max' => 'Keep the note under 3,000 characters.',
        ], ['phone' => 'mobile']);

        return [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'branch_id' => $data['branchId'] ?? null,
            'sales_rep_id' => $data['salesRepId'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function units(Guest $guest): array
    {
        $user = $this->user();

        return $guest->vehicles()
            ->with('vehicleSize')
            ->withCount(['bookings as jobs_count' => fn (Builder $bookings) => $bookings->visibleTo($user)])
            ->withMax(['bookings as last_service_at' => fn (Builder $bookings) => $bookings->visibleTo($user)->where('status', 'exited')], 'exited_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Vehicle $unit) => [
                'id' => $unit->getKey(),
                'year' => $unit->year,
                'model' => $unit->model,
                'type' => $unit->type ? (Booking::VEHICLE_TYPES[$unit->type] ?? $unit->type) : null,
                'plate' => $unit->plate_number,
                'size' => $unit->vehicleSize?->name,
                'sizeId' => $unit->vehicle_size_id ? (string) $unit->vehicle_size_id : null,
                'reviewed' => $unit->isReviewed(),
                'jobs' => (int) $unit->jobs_count,
                'lastService' => $unit->last_service_at ? date('M j, Y', strtotime((string) $unit->last_service_at)) : null,
                'lastServiceAt' => $unit->last_service_at ? (string) $unit->last_service_at : '',
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bookings(Guest $guest): array
    {
        return $guest->bookings()
            ->visibleTo($this->user())
            ->with(['branch', 'items'])
            ->latest('id')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->getKey(),
                'reference' => $booking->reference,
                'when' => $booking->whenLabel(),
                'services' => $booking->servicesLabel(),
                'branch' => $booking->branch?->name,
                'status' => BookingDesk::statusLabel($booking),
                'tone' => BookingDesk::statusColor($booking->status),
                'price' => $booking->total_price === null ? 'Size needed' : '₱'.number_format($booking->total_price),
            ])
            ->all();
    }

    /**
     * Who used their code. A booking links only when this person may open it.
     *
     * @return list<array<string, mixed>>
     */
    private function referrals(Guest $guest): array
    {
        $redemptions = $guest->referralRedemptions()->with(['usedBy', 'booking'])->latest('id')->get();
        $open = Booking::query()->visibleTo($this->user())->whereKey($redemptions->pluck('booking_id')->filter())->pluck('id')->flip();

        return $redemptions->map(fn (ReferralRedemption $redemption) => [
            'id' => $redemption->getKey(),
            'usedBy' => $redemption->usedBy?->name,
            'reference' => $redemption->booking?->reference,
            'bookingId' => $open->has($redemption->booking_id) ? $redemption->booking_id : null,
            'status' => ucfirst($redemption->status()),
            'tone' => match ($redemption->status()) {
                'awarded' => 'success',
                'voided' => 'gray',
                default => 'warning',
            },
            'discount' => $redemption->discount_amount === null ? '—' : '₱'.number_format($redemption->discount_amount),
            'points' => $redemption->points_awarded,
        ])->all();
    }

    /**
     * @param  array<int|string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, int|string $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }
}
