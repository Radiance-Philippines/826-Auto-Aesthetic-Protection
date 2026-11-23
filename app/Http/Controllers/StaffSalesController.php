<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\VehicleSize;
use App\Support\Reservations;
use App\Support\Staff\StaffViews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff app's Today tab, for the sales side (bookings.sales): drop-offs
 * today, units to size, payment screenshots to check and home requests; and
 * the actions on them (size, approve or reject a payment). The actions are
 * the admin's: Reservations::reviewSize, confirmPayment and rejectPayment.
 */
class StaffSalesController extends StaffController
{
    private const LIST_LIMIT = 100;

    /**
     * GET /api/staff/today?branch= (no branch: all of this user's branches)
     */
    public function today(Request $request): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $user = $this->user();
        $branch = $this->pickedBranch($request);

        $bookings = fn (): Builder => Booking::query()
            ->visibleTo($user)
            ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
            ->with(['items', 'branch'])
            ->orderBy('id');

        $cards = fn (Builder $query) => $query->limit(self::LIST_LIMIT)->get()->map(fn (Booking $booking) => StaffViews::card($booking, $user))->values()->all();

        return response()->json([
            'branch' => $branch ? ['id' => $branch->getKey(), 'name' => $branch->name] : null,
            'date' => today()->toDateString(),
            'dropOffsToday' => $cards($bookings()->where('kind', 'onsite')->where('status', 'confirmed')->where('drop_off_on', today()->toDateString())),
            'needsSizeReview' => $cards($bookings()->needsSizeReview()),
            'paymentsToCheck' => $bookings()->where('status', 'payment_review')->limit(self::LIST_LIMIT)->get()->map(fn (Booking $booking) => [
                ...StaffViews::card($booking, $user),
                'payment' => [
                    'method' => $booking->payment_method,
                    'amountDue' => $booking->amount_due,
                    'submittedAt' => $booking->payment_submitted_at?->toIso8601String(),
                    'proofUrl' => $booking->payment_proof ? url("/api/staff/bookings/{$booking->getKey()}/proof") : null,
                ],
            ])->values()->all(),
            'homeRequests' => $bookings()->where('kind', 'home')->where('status', 'requested')->limit(self::LIST_LIMIT)->get()->map(fn (Booking $booking) => [
                ...StaffViews::card($booking, $user),
                'preferredDate' => $booking->preferred_date?->toDateString(),
                'preferredWindow' => $booking->preferred_window,
                'address' => $booking->address,
                'area' => $booking->area,
            ])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/staff/vehicle-sizes
     */
    public function vehicleSizes(): JsonResponse
    {
        $this->requireArea('bookings.sales');

        return response()->json(VehicleSize::query()->active()->ordered()->get()->map(fn (VehicleSize $size) => [
            'id' => $size->getKey(),
            'name' => $size->name,
            'examples' => $size->examples,
        ])->values()->all());
    }

    /**
     * POST /api/staff/bookings/{id}/size {vehicle_size_id}: prices the booking
     * from the class; `missingPrices` names services that have no price for it.
     */
    public function size(Request $request, int $id): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $data = $request->validate(['vehicle_size_id' => ['required', 'integer']]);
        $booking = $this->visibleBooking($id);
        $size = VehicleSize::query()->active()->find($data['vehicle_size_id']);

        if (! $size) {
            return response()->json(['message' => 'Pick one of the size classes.', 'errors' => ['vehicle_size_id' => ['Pick one of the size classes.']]], 422);
        }

        if (! $booking->isOpen()) {
            return response()->json(['message' => 'This booking is finished, so its size can’t be changed.'], 422);
        }

        $missing = Reservations::reviewSize($booking, $size);

        return response()->json([...StaffViews::detail($booking->refresh(), $this->user()), 'missingPrices' => $missing]);
    }

    /**
     * POST /api/staff/bookings/{id}/payment/approve {note?}: the fee matches, the booking is confirmed.
     */
    public function approvePayment(Request $request, int $id): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $booking = $this->visibleBooking($id);

        if (! in_array($booking->stage(), ['awaiting_payment', 'payment_review'], true)) {
            return response()->json(['message' => 'This booking isn’t waiting for a payment check.'], 422);
        }

        Reservations::confirmPayment($booking, $data['note'] ?? null);

        return response()->json(StaffViews::detail($booking->refresh(), $this->user()));
    }

    /**
     * POST /api/staff/bookings/{id}/payment/reject {reason}: asks the customer for another screenshot.
     */
    public function rejectPayment(Request $request, int $id): JsonResponse
    {
        $this->requireArea('bookings.sales');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Tell the customer why.']);
        $booking = $this->visibleBooking($id);

        if ($booking->status !== 'payment_review') {
            return response()->json(['message' => 'There’s no screenshot to reject on this booking.'], 422);
        }

        Reservations::rejectPayment($booking, trim($data['reason']));

        return response()->json(StaffViews::detail($booking->refresh(), $this->user()));
    }
}
