<?php

namespace App\Http\Controllers;

use App\Models\Backjob;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Guest;
use App\Support\Warranty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The React admin's Warranty checker: the Filament page's search, backjob
 * log and "Mark done", through App\Support\Warranty.
 */
class ConsoleWarrantyController extends StaffController
{
    /**
     * GET ?q=&customer=
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireArea('warranty');

        $user = $this->user();
        $guest = null;

        if (filled($customer = $request->query('customer'))) {
            $guest = is_numeric($customer) ? Guest::query()->visibleTo($user)->find((int) $customer) : null;
            abort_unless($guest, 404, 'Customer not found.');
        }

        $search = is_string($request->query('q')) ? $request->query('q') : '';

        return response()->json([
            'guest' => $guest ? ['id' => $guest->getKey(), 'name' => $guest->name] : null,
            'searched' => trim($search) !== '' || $guest !== null,
            'data' => Warranty::search($user, $search, $guest?->getKey())->map(fn (Booking $booking) => [
                'id' => $booking->getKey(),
                'reference' => $booking->reference,
                'name' => $booking->name,
                'phone' => $booking->phone,
                'unit' => $booking->vehicleLabel(),
                'branch' => $booking->branch?->name,
                'finished' => $booking->exited_at?->format('M j, Y'),
                'items' => $booking->items->map(fn (BookingItem $item) => [
                    'id' => $item->getKey(),
                    'name' => $item->service_name,
                    'months' => $item->warranty_months,
                    'ends' => Warranty::endsOn($booking, $item)?->format('M j, Y'),
                    'status' => Warranty::status($booking, $item),
                    'covers' => $item->service?->backjobTypes->pluck('name')->all() ?? [],
                    'types' => collect(Warranty::typeOptions($item))->map(fn (string $label, int|string $value) => ['value' => (string) $value, 'label' => $label])->values()->all(),
                ])->all(),
                'backjobs' => $booking->backjobs->map(fn (Backjob $backjob) => [
                    'id' => $backjob->getKey(),
                    'on' => $backjob->noted_on->format('M j, Y'),
                    'service' => $backjob->item?->service_name,
                    'type' => $backjob->type?->name ?? 'Other',
                    'note' => $backjob->note,
                    'open' => $backjob->isOpen(),
                ])->all(),
            ])->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * POST backjobs {bookingId, bookingItemId, backjobTypeId, notedOn, note}
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireArea('warranty');

        $booking = Warranty::finishedJob($this->user(), $request->input('bookingId'));
        abort_unless($booking, 404, 'Booking not found.');

        $item = $booking->items->firstWhere('id', (int) $request->input('bookingItemId'));

        $data = $request->validate([
            'bookingItemId' => ['required', Rule::in($booking->items->modelKeys())],
            'backjobTypeId' => ['required', Rule::in(array_keys(Warranty::typeOptions($item)))],
            'notedOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'bookingItemId.required' => 'Pick the service.',
            'bookingItemId.in' => 'Pick a service from this booking.',
            'backjobTypeId.required' => 'Pick the type.',
            'backjobTypeId.in' => 'This service doesn’t cover that type. Pick Other.',
            'notedOn.required' => 'Pick the date.',
            'notedOn.before_or_equal' => 'The date can’t be after today.',
            'note.max' => 'Keep the note under 2,000 characters.',
        ]);

        $backjob = Warranty::logBackjob($this->user(), [
            'booking_id' => $booking->getKey(),
            'booking_item_id' => $data['bookingItemId'],
            'backjob_type_id' => $data['backjobTypeId'],
            'noted_on' => $data['notedOn'],
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['id' => $backjob->getKey()], 201);
    }

    public function done(int $id): Response
    {
        $this->requireArea('warranty');

        Warranty::finishBackjob($this->user(), $id);

        return response()->noContent();
    }
}
