<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\Branch;
use App\Support\Reservations;
use App\Support\Schedule\Availability;
use App\Support\Schedule\Estimate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which days a branch has room for, for the services a customer picked.
 * Days only: who booked them is never sent.
 */
class AvailabilityController extends Controller
{
    use ReadsBookingInput;

    /**
     * GET /api/availability?branch=1&services[]=3&month=2026-11
     * (or &booking=12&key=… to move an existing booking).
     */
    public function month(Request $request): JsonResponse
    {
        [$branch, $estimate, $exceptId] = $this->job($request);

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : CarbonImmutable::today();

        $month = $month->startOfMonth()
            ->max(CarbonImmutable::today()->startOfMonth())
            ->min(Availability::lastDay($branch)->startOfMonth());

        return response()->json([
            ...Availability::month($branch, $month, $exceptId),
            'estimate' => $estimate->toArray(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The branch and the job's estimate: from the picked services, or from
     * the booking being moved.
     *
     * @return array{0: Branch, 1: Estimate, 2: int|null}
     */
    private function job(Request $request): array
    {
        if ($request->filled('booking')) {
            $booking = $this->openableBooking($request, $request->query('booking'));
            abort_unless($booking->isOnsite() && $booking->branch, 404);

            return [$booking->branch, $booking->estimate(), $booking->id];
        }

        $branch = $this->listedBranch($request->query('branch'));
        abort_unless($branch->isOnsiteBookable(), 409, 'This branch doesn’t take online bookings yet.');

        return [$branch, Reservations::quote($branch, $this->serviceIds($request))['estimate'], null];
    }
}
