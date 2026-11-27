<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\VehicleSize;
use App\Support\BookingDesk;
use App\Support\ManualTransaction;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The React admin's Bookings: the Filament resource's list, view, create and
 * edit as JSON, with its steps. Size, payment, queue steps, ready date and
 * "Assigned to" are the staff app's own endpoints (routes/admin/bookings.php).
 */
class ConsoleBookingController extends StaffController
{
    private const PER_PAGE = 25;

    /** Filament's tel() rule */
    public const PHONE = '/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/';

    /** Form field => column */
    private const FIELDS = [
        'branchId' => 'branch_id',
        'dropOffOn' => 'drop_off_on',
        'preferredDate' => 'preferred_date',
        'preferredWindow' => 'preferred_window',
        'vehicleYear' => 'vehicle_year',
        'vehicleModel' => 'vehicle_model',
        'vehicleType' => 'vehicle_type',
        'plateNumber' => 'plate_number',
        'vehicleSizeId' => 'vehicle_size_id',
        'hasWater' => 'has_water',
        'hasPower' => 'has_power',
        'hasShade' => 'has_shade',
        'adminNote' => 'admin_note',
    ];

    /**
     * GET ?tab=&search=&branch=&kind=&status[]=&sort=dropOff|made&desc=1&page=
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Booking::class);

        $user = $this->user();
        $branches = BookingDesk::branchOptions($user);
        $tab = array_key_exists((string) $request->query('tab'), BookingDesk::TABS) ? (string) $request->query('tab') : BookingDesk::DEFAULT_TAB;
        $scoped = fn (): Builder => Booking::query()->visibleTo($user);

        $query = BookingDesk::tab($scoped()->with(['branch', 'items']), $tab);

        $branch = (int) $request->query('branch');
        $query->when(isset($branches[$branch]), fn (Builder $bookings) => $bookings->where('branch_id', $branch));
        $kind = (string) $request->query('kind');
        $query->when(array_key_exists($kind, Booking::KINDS), fn (Builder $bookings) => $bookings->where('kind', $kind));
        $statuses = array_values(array_intersect(array_keys(Booking::STATUSES), array_filter((array) $request->query('status', []), 'is_string')));
        $query->when($statuses !== [], fn (Builder $bookings) => $bookings->whereIn('status', $statuses));

        // Every word must be somewhere, as the Filament table searches
        foreach (preg_split('/\s+/', trim((string) $request->query('search')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$word}%")
                ->orWhere('phone', 'like', "%{$word}%")
                ->orWhere('reference', 'like', "%{$word}%")
                ->orWhere('plate_number', 'like', "%{$word}%"));
        }

        $sort = ['dropOff' => 'drop_off_on', 'made' => 'created_at'][(string) $request->query('sort')] ?? null;

        if ($sort) {
            $query->reorder($sort, $request->boolean('desc') ? 'desc' : 'asc');
        } else {
            // After the tab's own order, where it has one (Done, Cancelled)
            $query->orderBy('drop_off_on');
        }

        $page = $query->orderBy('id')->paginate(self::PER_PAGE);
        $seesCustomers = CustomerResource::canViewAny();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Booking $booking) => $this->row($booking, $seesCustomers))->all(),
            'meta' => ['total' => $page->total(), 'perPage' => $page->perPage()],
            'tab' => $tab,
            'tabs' => collect(BookingDesk::TABS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'badge' => in_array($key, BookingDesk::COUNTED_TABS, true) ? BookingDesk::tab($scoped(), $key)->count() : 0,
            ])->values()->all(),
            'filters' => [
                'branches' => count($branches) > 1 ? $this->options($branches) : [],
                'kinds' => $this->options(Booking::KINDS),
                'statuses' => $this->options(Booking::STATUSES),
            ],
            'can' => ['create' => Gate::allows('create', Booking::class), 'delete' => Gate::allows('deleteAny', Booking::class)],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Opening a booking marks it as read.
     */
    public function show(int $id): JsonResponse
    {
        $booking = $this->booking($id, 'view');

        if (! $booking->isRead()) {
            $booking->update(['read_at' => now()]);
        }

        return response()->json($this->detail($booking))->header('Cache-Control', 'no-store');
    }

    /**
     * What the create and edit forms offer: GET form?branch=&kind=&date=
     */
    public function form(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Booking::class);

        $user = $this->user();
        $branches = BookingDesk::branchOptions($user);
        $home = $request->query('kind') === 'home';
        $id = (int) $request->query('branch');
        $branch = isset($branches[$id]) ? Branch::query()->visibleTo($user)->find($id) : null;
        $date = $this->date($request->query('date'));

        return response()->json([
            'branches' => $this->options($branches),
            'services' => $branch ? $this->options(BookingDesk::serviceOptions($branch->id, $home)) : [],
            'blocked' => $branch && ! $home ? Availability::blockedForStaff($branch, $user) : [],
            'canOverbook' => $branch && ! $home && $date && Availability::canOverbook($branch, $date, $user),
            'sizes' => $this->sizes(),
            'kinds' => $this->options(Booking::KINDS),
            'sources' => $this->options(['walk_in' => 'Walk-in', 'phone' => 'Call or message', 'admin' => 'Other']),
            'windows' => $this->options(Booking::WINDOWS),
            'types' => $this->options(Booking::VEHICLE_TYPES),
            'maxYear' => now()->year + 1,
            'can' => ['create' => Gate::allows('create', Booking::class)],
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Booking::class);

        $user = $this->user();
        $home = $request->input('kind') === 'home';
        $branch = is_numeric($request->input('branchId')) ? (int) $request->input('branchId') : null;

        $data = $this->check($request, [
            'kind' => ['required', Rule::in(array_keys(Booking::KINDS))],
            'source' => ['required', Rule::in(['walk_in', 'phone', 'admin'])],
            'branchId' => ['required', Rule::in(array_keys(BookingDesk::branchOptions($user)))],
            'services' => ['required', 'array'],
            'services.*' => ['integer', Rule::in(array_keys(BookingDesk::serviceOptions($branch, $home)))],
            'dropOffOn' => [$home ? 'nullable' : 'required', 'date_format:Y-m-d'],
            'force' => ['boolean'],
            'preferredDate' => ['nullable', 'date_format:Y-m-d'],
            'preferredWindow' => ['nullable', Rule::in(array_keys(Booking::WINDOWS))],
            'vehicleSizeId' => ['nullable', Rule::in(array_column($this->sizes(), 'value'))],
            ...$this->vehicleRules('required'),
            ...$this->customerRules(),
            ...($home ? $this->homeRules() : []),
        ]);

        // A home field sent with an onsite booking means nothing, as in the Filament form
        $data = $home ? $data : array_diff_key($data, array_flip(['preferred_date', 'preferred_window']));

        try {
            $booking = BookingDesk::book($user, $data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['dropOffOn' => collect($e->errors())->flatten()->first()]);
        } catch (LockTimeoutException) {
            return $this->busy();
        }

        return response()->json(['id' => $booking->getKey(), 'message' => BookingDesk::bookedTitle($booking)], 201);
    }

    /**
     * The values the edit form starts from.
     */
    public function edit(int $id): JsonResponse
    {
        $booking = $this->booking($id, 'update');

        return response()->json([
            'id' => $booking->getKey(),
            'title' => "{$booking->name} · {$booking->reference}",
            'isHome' => $booking->isHome(),
            'sales' => $this->user()->canAccessArea('bookings.sales'),
            'canDelete' => Gate::allows('delete', $booking),
            'values' => [
                'vehicleYear' => $booking->vehicle_year,
                'vehicleModel' => $booking->vehicle_model,
                'vehicleType' => $booking->vehicle_type,
                'plateNumber' => $booking->plate_number,
                'address' => $booking->address,
                'area' => $booking->area,
                'landmark' => $booking->landmark,
                'hasWater' => $booking->has_water,
                'hasPower' => $booking->has_power,
                'hasShade' => $booking->has_shade,
                'name' => $booking->name,
                'phone' => $booking->phone,
                'email' => $booking->email,
                'message' => $booking->message,
                'adminNote' => $booking->admin_note,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The customer's details, vehicle and notes. The date and services change
     * through Move and Change ready date. Without the sales area only the
     * vehicle is saved (the rest of the Filament form is disabled).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $booking = $this->booking($id, 'update');
        $sales = $this->user()->canAccessArea('bookings.sales');

        $booking->update($this->check($request, [
            ...$this->vehicleRules('nullable'),
            ...($sales ? $this->customerRules() : []),
            ...($sales && $booking->isHome() ? $this->homeRules() : []),
        ]));

        BookingDesk::afterEdit($booking);

        return response()->json(['id' => $booking->getKey(), 'message' => 'Saved']);
    }

    public function destroy(int $id): Response
    {
        $booking = $this->booking($id, 'delete');
        $booking->delete();

        return response()->noContent();
    }

    /**
     * The list's bulk delete: {ids}
     */
    public function destroyMany(Request $request): JsonResponse
    {
        Gate::authorize('deleteAny', Booking::class);

        $bookings = $this->selected($request)->get();
        $bookings->each->delete();

        return response()->json(['count' => $bookings->count()]);
    }

    /**
     * The list's bulk "Mark as read" and "Mark as unread": {ids, read}
     */
    public function markMany(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Booking::class);

        $read = $request->validate(['read' => ['required', 'boolean']])['read'];
        $bookings = $this->selected($request);

        $count = $read ? $bookings->unread()->update(['read_at' => now()]) : $bookings->update(['read_at' => null]);

        return response()->json(['count' => $count]);
    }

    public function markUnread(int $id): Response
    {
        $this->booking($id, 'view')->update(['read_at' => null]);

        return response()->noContent();
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $booking = $this->offered($id, 'cancelBooking');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:250']], ['reason.max' => 'Keep the reason under 250 characters.']);

        BookingDesk::cancel($booking, $data['reason'] ?? null);

        return response()->json($this->detail($booking->refresh()));
    }

    public function noShow(int $id): JsonResponse
    {
        $booking = $this->offered($id, 'noShow');
        $booking->update(['status' => 'no_show']);

        return response()->json($this->detail($booking->refresh()));
    }

    /**
     * What Move offers: GET {id}/dates?date=
     */
    public function dates(Request $request, int $id): JsonResponse
    {
        $booking = $this->offered($id, 'reschedule');
        $date = $this->date($request->query('date'));

        return response()->json([
            'blocked' => Availability::blockedForStaff($booking->branch, $this->user(), $booking->id),
            'canOverbook' => $date !== null && Availability::canOverbook($booking->branch, $date, $this->user(), $booking->id),
        ])->header('Cache-Control', 'no-store');
    }

    public function reschedule(Request $request, int $id): JsonResponse
    {
        $booking = $this->offered($id, 'reschedule');
        $data = $request->validate(['dropOffOn' => ['required', 'date_format:Y-m-d'], 'force' => ['boolean']], ['dropOffOn.required' => 'Pick the new drop-off date.']);

        try {
            BookingDesk::reschedule($booking, CarbonImmutable::parse($data['dropOffOn']), (bool) ($data['force'] ?? false));
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['dropOffOn' => collect($e->errors())->flatten()->first()]);
        } catch (LockTimeoutException) {
            return $this->busy();
        }

        return response()->json($this->detail($booking->refresh()));
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        $booking = $this->offered($id, 'scheduleHome');
        $data = $request->validate([
            'dropOffOn' => ['required', 'date_format:Y-m-d'],
            'readyOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dropOffOn'],
            'askFee' => ['boolean'],
        ], [
            'dropOffOn.required' => 'Pick the day the team comes.',
            'readyOn.after_or_equal' => '“Done by” can’t be before the day the team comes.',
        ]);

        BookingDesk::scheduleHome(
            $booking,
            CarbonImmutable::parse($data['dropOffOn']),
            isset($data['readyOn']) ? CarbonImmutable::parse($data['readyOn']) : null,
            (bool) ($data['askFee'] ?? false),
        );

        return response()->json($this->detail($booking->refresh()));
    }

    /**
     * A booking at one of this person's branches (404 otherwise) that they may $ability.
     */
    private function booking(int $id, string $ability): Booking
    {
        $booking = $this->visibleBooking($id);
        Gate::authorize($ability, $booking);

        return $booking;
    }

    /**
     * The same for a step: its area (403), and the booking must be at a point that offers it (422).
     */
    private function offered(int $id, string $action): Booking
    {
        $this->requireArea(BookingDesk::AREAS[$action]);
        $booking = $this->visibleBooking($id);

        if (! BookingDesk::offers($action, $booking)) {
            throw ValidationException::withMessages(['booking' => 'This booking has changed and no longer offers that. Reload the page.']);
        }

        return $booking;
    }

    private function selected(Request $request): Builder
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']])['ids'];

        // Another branch's ids are simply not found
        return Booking::query()->visibleTo($this->user())->whereKey($ids);
    }

    private function busy(): JsonResponse
    {
        return response()->json(['message' => 'Busy, try again', 'errors' => []], 422);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::parse($value)->startOfDay() : null;
    }

    /**
     * Validates the camelCase form and returns it by column name.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function check(Request $request, array $rules): array
    {
        $data = $request->validate($rules, [
            'services.required' => 'Pick at least one service.',
            'dropOffOn.required' => 'Pick the drop-off date.',
            'phone.regex' => 'Use numbers only, like 0917 123 4567.',
        ], [
            'branchId' => 'branch',
            'dropOffOn' => 'date',
            'preferredDate' => 'preferred day',
            'preferredWindow' => 'preferred time',
            'vehicleYear' => 'year',
            'vehicleModel' => 'make and model',
            'vehicleType' => 'type',
            'plateNumber' => 'plate number',
            'vehicleSizeId' => 'size class',
            'phone' => 'mobile',
            'message' => 'note from the customer',
            'adminNote' => 'private note',
            'hasWater' => 'water hose',
            'hasPower' => 'power outlet',
            'hasShade' => 'shade',
        ]);

        unset($data['services.*']);

        return collect($data)->mapWithKeys(fn (mixed $value, string $field) => [self::FIELDS[$field] ?? $field => $value])->all();
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function vehicleRules(string $presence): array
    {
        return [
            'vehicleYear' => [$presence, 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'vehicleModel' => [$presence, 'string', 'max:80'],
            'vehicleType' => [$presence, Rule::in(array_keys(Booking::VEHICLE_TYPES))],
            'plateNumber' => [$presence, 'string', 'max:15'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function customerRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40', 'regex:'.self::PHONE],
            'email' => ['nullable', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:1000'],
            'adminNote' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function homeRules(): array
    {
        return [
            'address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'landmark' => ['nullable', 'string', 'max:200'],
            'hasWater' => ['nullable', 'boolean'],
            'hasPower' => ['nullable', 'boolean'],
            'hasShade' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<int|string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, int|string $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function sizes(): array
    {
        return $this->options(VehicleSize::query()->active()->ordered()->pluck('name', 'id')->all());
    }

    private function peso(int $amount): string
    {
        return '₱'.number_format($amount);
    }

    private function tone(Booking $booking): string
    {
        return $booking->stage() === 'expired' ? 'gray' : BookingDesk::statusColor($booking->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Booking $booking, bool $seesCustomers): array
    {
        return [
            'id' => $booking->getKey(),
            'reference' => $booking->reference,
            'unread' => ! $booking->isRead(),
            'dropOff' => $booking->drop_off_on ? $booking->drop_off_on->format('D, M j') : 'Asked for '.$booking->preferred_date?->format('D, M j'),
            'dropOffNote' => $booking->ready_on ? 'Ready around '.$booking->ready_on->format('D, M j') : Booking::KINDS[$booking->kind],
            'name' => $booking->name,
            'phone' => $booking->phone,
            'phoneUrl' => BookingDesk::phoneUrl($booking->phone),
            'customerId' => $seesCustomers ? $booking->guest_id : null,
            'services' => $booking->servicesLabel(),
            'vehicle' => $booking->vehicleLabel(),
            'branch' => $booking->branch?->name,
            'status' => BookingDesk::statusLabel($booking),
            'tone' => $this->tone($booking),
            'statusNote' => $booking->stage() === 'awaiting_payment' && $booking->hold_expires_at ? 'until '.$booking->hold_expires_at->format('g:i A') : null,
            'price' => $booking->total_price === null ? 'Size needed' : $this->peso($booking->total_price),
            'needsSize' => $booking->needsSizeReview(),
            'made' => $booking->created_at?->diffForHumans(),
        ];
    }

    /**
     * The view page: what the Filament infolist shows, and the actions this person is offered.
     *
     * @return array<string, mixed>
     */
    private function detail(Booking $booking): array
    {
        $user = $this->user();
        $booking->load(['branch', 'items', 'guest', 'vehicle']);
        $sales = $user->canAccessArea('bookings.sales');
        $payments = PaymentSetting::instance();
        $time = 'M j, Y g:i A';
        $fee = $sales && $booking->source !== 'manual' && ($booking->amount_due !== null || filled($booking->payment_proof) || $booking->paid_at !== null);
        $actions = array_values(array_filter(array_keys(BookingDesk::AREAS), fn (string $action) => BookingDesk::allows($user, $action, $booking)));

        return [
            'id' => $booking->getKey(),
            'title' => "{$booking->name} · {$booking->reference}",
            'reference' => $booking->reference,
            'branchId' => $booking->branch_id,
            'whenLabel' => $booking->isHome() ? 'Schedule' : 'Drop-off · ready around',
            'when' => $booking->whenLabel(),
            'branch' => $booking->branch?->name,
            'assignedTo' => $booking->assigned_to,
            'kind' => $booking->kind,
            'kindLabel' => Booking::KINDS[$booking->kind] ?? $booking->kind,
            'services' => $booking->items->map(fn ($item) => $item->service_name.' · '.($item->price !== null ? $this->peso($item->price) : 'price after review'))->all(),
            'price' => $booking->total_price === null ? 'Not set: review the unit' : $this->peso($booking->total_price),
            'needsSize' => $booking->needsSizeReview(),
            'discountNote' => $booking->discount_amount ? 'After a '.$this->peso($booking->discount_amount).' referral discount' : null,
            'takes' => $booking->estimate()->label() ?: '—',
            'arrivedAt' => $booking->arrived_at?->format($time),
            'exitedAt' => $booking->exited_at?->format($time),
            'message' => $booking->message,
            'fee' => $fee ? [
                'amount' => $booking->amount_due === null ? '—' : $this->peso($booking->amount_due),
                'paidNote' => $booking->paid_at ? 'Paid '.$booking->paid_at->format('M j, g:i A').($booking->payment_note ? " · {$booking->payment_note}" : '') : null,
                'method' => $booking->payment_method ? (PaymentSetting::METHODS[$booking->payment_method] ?? $booking->payment_method) : null,
                'methodNote' => $booking->payment_submitted_at ? 'Screenshot sent '.$booking->payment_submitted_at->format('M j, g:i A') : null,
                'rejectedReason' => filled($booking->payment_rejected_reason) && $booking->stage() === 'awaiting_payment' ? $booking->payment_rejected_reason : null,
                // The admin's own private file route: the sales side of that branch only
                'proofUrl' => filled($booking->payment_proof) ? route('admin.bookings.proof', $booking) : null,
            ] : null,
            'payment' => $sales && $booking->source === 'manual' ? [
                'paid' => $booking->amount_paid === null ? 'Nothing recorded' : $this->peso($booking->amount_paid),
                'paidNote' => $booking->paid_at ? 'On '.$booking->paid_at->format('M j, Y') : null,
                'method' => $booking->payment_method ? (ManualTransaction::METHODS[$booking->payment_method] ?? $booking->payment_method) : null,
                'note' => $booking->payment_note,
            ] : null,
            'home' => $booking->isHome() ? [
                'address' => $booking->address,
                'area' => $booking->area,
                'landmark' => $booking->landmark,
                'site' => collect(['has_water', 'has_power', 'has_shade'])->map(fn (string $field) => $booking->{$field} === null ? 'not said' : ($booking->{$field} ? 'yes' : 'no'))->implode(' / '),
            ] : null,
            'history' => $booking->events()->with('user')->limit(100)->get()->map(fn (BookingEvent $event) => $event->line())->all(),
            'status' => BookingDesk::statusLabel($booking),
            'tone' => $this->tone($booking),
            'holdNote' => $booking->stage() === 'awaiting_payment' && $booking->hold_expires_at ? 'Held until '.$booking->hold_expires_at->format('M j, g:i A') : null,
            'name' => $booking->name,
            'customerId' => $booking->guest_id && CustomerResource::canViewAny() ? $booking->guest_id : null,
            'accountNote' => $booking->guest?->email ? "Signed in as {$booking->guest->email}" : ($booking->guest ? 'Customer record, no Google account' : null),
            'phone' => $booking->phone,
            'phoneUrl' => BookingDesk::phoneUrl($booking->phone),
            'smsUrl' => BookingDesk::smsUrl($booking),
            'email' => $booking->email,
            'mailUrl' => BookingDesk::mailUrl($booking),
            'vehicle' => $booking->vehicleLabel() ?: null,
            'sizeName' => $booking->size_reviewed_at ? $booking->vehicle_size_name : null,
            'adminNote' => $booking->admin_note,
            'madeLabel' => 'Came in by '.strtolower(Booking::SOURCES[$booking->source] ?? $booking->source),
            'madeAt' => $booking->created_at?->format($time),
            'movedNote' => $booking->reschedule_count > 0 ? "Moved by the customer {$booking->reschedule_count}×" : null,
            'link' => $booking->customerUrl(),
            'actions' => $actions,
            'can' => ['edit' => Gate::allows('update', $booking), 'delete' => Gate::allows('delete', $booking)],
            'values' => [
                'status' => $booking->status,
                'next' => $booking->nextStatus(),
                'paymentNote' => $booking->payment_note,
                'vehicleSizeId' => $booking->vehicle_size_id ? (string) $booking->vehicle_size_id : null,
                'dropOffOn' => $booking->drop_off_on?->toDateString(),
                'readyOn' => $booking->ready_on?->toDateString(),
                'preferredDate' => $booking->preferred_date?->toDateString(),
                'paid' => $booking->paid_at !== null,
                'holdMinutes' => $payments->holdMinutes(),
                // Null: the fee is not asked for on this booking
                'askFeeLabel' => $payments->feeEnabled() && $booking->status === 'requested' ? 'Ask for the reservation fee ('.($payments->feeLabel() ?? 'off in Payments').')' : null,
                'takes' => $booking->estimate()->label(),
            ],
            'sizes' => in_array('setSize', $actions, true) ? $this->sizes() : [],
        ];
    }
}
