<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The base of the staff app's endpoints (routes/api.php, /api/staff). Every
 * one enforces the admin's own rules on the server: a missing area is a
 * 403, and another branch's record is a 404 (never a hint that it exists).
 */
abstract class StaffController extends Controller
{
    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }

    /**
     * 403 unless the user has at least one of these areas.
     */
    protected function requireArea(string ...$areas): void
    {
        abort_unless($this->user()->canAccessAnyArea(...$areas), 403, 'Your role doesn’t include this.');
    }

    /**
     * The branch picked with ?branch=, when this user may see it (404 if not); null when none is asked for.
     */
    protected function pickedBranch(Request $request): ?Branch
    {
        $id = $request->query('branch');

        if (! filled($id)) {
            return null;
        }

        $branch = is_numeric($id) ? Branch::query()->visibleTo($this->user())->find((int) $id) : null;
        abort_unless($branch, 404, 'Branch not found.');

        return $branch;
    }

    /**
     * A booking at one of this user's branches (404 otherwise).
     */
    protected function visibleBooking(int|string $id): Booking
    {
        $booking = is_numeric($id) ? Booking::query()->visibleTo($this->user())->find((int) $id) : null;
        abort_unless($booking, 404, 'Booking not found.');

        return $booking;
    }
}
