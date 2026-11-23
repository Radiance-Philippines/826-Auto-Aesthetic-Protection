<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Guest;
use App\Models\Vehicle;
use App\Support\InboxAccess;
use App\Support\Staff\StaffViews;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The staff app's Clients tab (customers.view): the customers who booked
 * at the person's branches (Guest::visibleTo, like the admin's Customers),
 * a profile with units, bookings and points, and notes and phone edits
 * (customers.write). Bookings shown are only the person's own branches'.
 */
class StaffCustomerController extends StaffController
{
    private const PER_PAGE = 20;

    /**
     * GET /api/staff/customers?q=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireArea('customers.view');

        $user = $this->user();
        $term = trim((string) $request->query('q', ''));
        $plate = Booking::normalizePlate($term);
        $digits = preg_replace('/\D/', '', $term);

        $page = Guest::query()
            ->visibleTo($user)
            ->with('branch:id,name')
            ->withCount(['vehicles', 'bookings' => fn (Builder $bookings) => $bookings->visibleTo($user)])
            ->withMax(['bookings as last_visit_on' => fn (Builder $bookings) => $bookings->visibleTo($user)->whereIn('status', ['arrived', 'in_progress', 'quality_check', 'ready', 'exited'])], 'drop_off_on')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('guests.name', 'like', "%{$term}%")
                ->orWhere('guests.phone', 'like', "%{$term}%")
                ->orWhere('guests.email', 'like', "%{$term}%")
                ->when(strlen($digits) >= 4, fn (Builder $or) => $or->orWhere('guests.phone_key', 'like', '%'.substr($digits, -10).'%'))
                ->when($plate !== '', fn (Builder $or) => $or->orWhereHas('vehicles', fn (Builder $vehicles) => $vehicles->where('plate_number', 'like', "%{$plate}%")))))
            ->orderBy('guests.name')
            ->orderBy('guests.id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Guest $guest) => [
                'id' => $guest->getKey(),
                'name' => $guest->name,
                'phone' => $guest->phone,
                'email' => $guest->email,
                'branch' => $guest->branch?->name,
                'unitsCount' => (int) $guest->vehicles_count,
                'bookingsCount' => (int) $guest->bookings_count,
                'lastVisitOn' => $guest->last_visit_on ? substr((string) $guest->last_visit_on, 0, 10) : null,
            ])->values()->all(),
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'hasMore' => $page->hasMorePages(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/staff/customers/{id}
     */
    public function show(int $id): JsonResponse
    {
        return response()->json($this->profile($this->visibleGuest($id, 'view')))->header('Cache-Control', 'no-store');
    }

    /**
     * PATCH /api/staff/customers/{id} {phone?, notes?}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireArea('customers.write');

        $guest = $this->visibleGuest($id, 'update');

        $data = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^\+?[\d\s().-]+$/', function (string $attribute, mixed $value, Closure $fail) {
                $digits = strlen(preg_replace('/\D/', '', (string) $value));

                if ($value !== null && ($digits < 10 || $digits > 13)) {
                    $fail('Enter the mobile number, like 0917 123 4567.');
                }
            }],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ], [
            'phone.regex' => 'Use numbers only, like 0917 123 4567.',
            'notes.max' => 'Keep the note under 3,000 characters.',
        ]);

        $guest->fill($data)->save();

        return response()->json($this->profile($guest->refresh()));
    }

    /**
     * The customer if this person's branches know them (404 otherwise), and the policy agrees.
     */
    private function visibleGuest(int $id, string $ability): Guest
    {
        $this->requireArea('customers.view');

        $guest = Guest::query()->visibleTo($this->user())->find($id);
        abort_unless($guest, 404, 'Customer not found.');
        Gate::forUser($this->user())->authorize($ability, $guest);

        return $guest;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Guest $guest): array
    {
        $user = $this->user();
        $money = StaffViews::seesMoney($user);

        $bookings = $guest->bookings()->visibleTo($user)->with(['items', 'branch:id,name'])->latest('id')->limit(50)->get();
        $history = $guest->bookings()->visibleTo($user)->selectRaw('vehicle_id, count(*) as total')->groupBy('vehicle_id')->pluck('total', 'vehicle_id');
        $chat = InboxAccess::allows($user) ? Conversation::query()->visibleTo($user)->where('guest_id', $guest->getKey())->latestFirst()->first() : null;

        return [
            'id' => $guest->getKey(),
            'name' => $guest->name,
            'phone' => $guest->phone,
            'email' => $guest->email,
            'branch' => $guest->branch ? ['id' => $guest->branch->getKey(), 'name' => $guest->branch->name] : null,
            'source' => $guest->source,
            'sourceLabel' => Guest::SOURCES[$guest->source] ?? $guest->source,
            'referralCode' => $guest->referral_code,
            'pointsBalance' => $guest->pointsBalance(),
            'notes' => $guest->notes,
            'units' => $guest->vehicles()->orderBy('model')->orderBy('id')->get()->map(fn (Vehicle $unit) => [
                'id' => $unit->getKey(),
                'label' => trim("{$unit->year} {$unit->model} ({$unit->typeLabel()})"),
                'plate' => $unit->plate_number,
                'size' => $unit->isReviewed() ? $unit->vehicleSize?->name : null,
                'historyCount' => (int) ($history[$unit->getKey()] ?? 0),
            ])->values()->all(),
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->getKey(),
                'reference' => $booking->reference,
                'status' => $booking->status,
                'statusLabel' => Booking::STATUSES[$booking->status] ?? $booking->status,
                'branch' => $booking->branch?->name,
                'unit' => $booking->vehicleLabel() ?: null,
                'servicesLabel' => $booking->servicesLabel(),
                'dropOffOn' => $booking->drop_off_on?->toDateString(),
                'readyOn' => $booking->ready_on?->toDateString(),
                'total' => $money ? $booking->total_price : null,
            ])->values()->all(),
            'messenger' => $chat ? ['conversationId' => $chat->getKey(), 'branchId' => $chat->branch_id] : null,
            'can' => ['edit' => $user->canAccessArea('customers.write')],
        ];
    }
}
