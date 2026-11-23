<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Support\Warranty;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/my-warranty: the signed-in customer's finished jobs and what
 * each service's warranty covers, per unit. Only what the shop stated:
 * a service with no warranty months shows status "none".
 */
class MyWarrantyController extends Controller
{
    use ReadsBookingInput;

    public function show(): JsonResponse
    {
        $guest = $this->guest();
        abort_unless($guest, 401);

        $bookings = $guest->bookings()
            ->where('status', 'exited')
            ->whereNotNull('exited_at')
            ->with(['items.service.backjobTypes'])
            ->latest('exited_at')
            ->get();

        $units = $bookings->groupBy(fn (Booking $booking) => $booking->vehicle_id ?? 'plate:'.$booking->plate_number)
            ->map(fn ($jobs) => [
                'vehicleId' => $jobs->first()->vehicle_id,
                'vehicleLabel' => $jobs->first()->vehicleLabel(),
                'services' => $jobs->flatMap(fn (Booking $booking) => $booking->items->map(fn (BookingItem $item) => [
                    'name' => $item->service_name,
                    'finishedOn' => $booking->exited_at->toDateString(),
                    'endsOn' => Warranty::endsOn($booking, $item)?->toDateString(),
                    'status' => Warranty::status($booking, $item),
                    'months' => $item->warranty_months ?: null,
                    'backjobTypesCovered' => $item->warranty_months && $item->service
                        ? $item->service->backjobTypes->where('is_active', true)->sortBy('sort_order')->pluck('name')->values()->all()
                        : [],
                ]))->values()->all(),
            ])
            ->values();

        return response()->json($units)->header('Cache-Control', 'no-store');
    }
}
