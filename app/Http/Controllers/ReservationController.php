<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\Booking;
use App\Support\Notifier;
use App\Support\Reservations;
use App\Support\Schedule\Availability;
use App\Support\Schedule\OpenCalendar;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/bookings: a customer reserves a drop-off date at a branch.
 * Their unit is kept for next time; the price comes after the admin has
 * reviewed it (at once, for a unit that was reviewed before).
 */
class ReservationController extends Controller
{
    use ReadsBookingInput;

    public function store(Request $request): JsonResponse
    {
        if (filled($request->input(self::HONEYPOT))) {
            return response()->json(['ok' => true], 201);
        }

        $guest = $this->guest();

        if (! $guest && ! config('app.demo')) {
            return response()->json(['message' => 'Sign in with Google to reserve.', 'signIn' => true], 401);
        }

        $details = $request->validate([
            'branch' => ['required', 'integer'],
            'services' => ['required', 'array', 'min:1', 'max:12'],
            'services.*' => ['integer'],
            'date' => ['required', 'string'],
            ...$this->vehicleRules(),
            ...$this->contactRules(),
        ], [
            'branch.required' => 'Pick a branch.',
            'services.required' => 'Pick at least one service.',
            'services.min' => 'Pick at least one service.',
            'date.required' => 'Pick a date on the calendar.',
            ...$this->contactMessages(),
        ]);

        $branch = $this->listedBranch($details['branch']);

        if (! $branch->isOnsiteBookable()) {
            throw ValidationException::withMessages(['branch' => "{$branch->name} doesn’t take online bookings yet. Call or message the branch."]);
        }

        $quote = Reservations::quote($branch, $this->serviceIds($request));
        $date = $this->dateFrom($details['date']);

        // A demo checks everything like the real site, then keeps nothing
        if (config('app.demo')) {
            $result = Availability::check($branch, $date);

            if ($result !== 'ok') {
                throw ValidationException::withMessages(['date' => Reservations::dateMessage($result)]);
            }

            $ready = OpenCalendar::for($branch, $date, $date)->readyOn($date, $quote['estimate']->days);

            return response()->json(['ok' => true, 'demo' => true, 'estimate' => $quote['estimate']->toArray(), 'readyOn' => $ready?->toDateString()], 201);
        }

        try {
            $booking = Reservations::reserve($branch, $guest, $quote, $date, $details);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Lots of people are booking right now. Try again in a moment.'], 409);
        }

        if (blank($guest->phone)) {
            $guest->update(['phone' => $details['phone']]);
        }

        $kind = $booking->status === 'awaiting_payment' ? 'hold' : 'reserved';
        Notifier::team($booking, $booking->needsSizeReview() ? 'size_review' : $kind);
        Notifier::customer($booking, $kind);

        return response()->json(['ok' => true, 'booking' => $this->fresh($booking)->toCustomer()], 201);
    }

    private function fresh(Booking $booking): Booking
    {
        return $booking->refresh()->load(['items', 'branch']);
    }
}
