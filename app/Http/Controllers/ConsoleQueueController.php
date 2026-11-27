<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Queue;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\BranchBlocks\BranchBlockResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Support\CurrentBranch;
use App\Support\QueueBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The React admin's Queue: the same board as the Filament page, as JSON.
 * Moving a unit and "Assigned to" are StaffQueueController's (see
 * routes/admin/queue.php).
 */
class ConsoleQueueController extends StaffController
{
    /**
     * GET /console/api/{panel}/queue?branch=&date=
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $branches = Branch::query()->visibleTo($user)->ordered()->get();
        // The top selector wins over a ?branch= left in the address
        $branch = (CurrentBranch::for($user) === null ? $this->pickedBranch($request) : null) ?? $branches->first();
        $day = QueueBoard::day($request->query('date'));

        $card = fn (Booking $booking) => [
            ...QueueBoard::card($booking, $user),
            'url' => "/bookings/{$booking->getKey()}",
        ];

        $payload = [
            'branch' => $branch ? ['id' => $branch->getKey(), 'name' => $branch->name] : null,
            'branches' => $branches->map(fn (Branch $each) => ['id' => $each->getKey(), 'name' => $each->name])->all(),
            'date' => $day->toDateString(),
            'heading' => $day->format('l, M j, Y'),
            'isToday' => $day->isToday(),
            'links' => [
                'newBooking' => BookingResource::canCreate() ? '/bookings/create' : null,
                'closures' => BranchBlockResource::canViewAny() ? BranchBlockResource::getUrl('index') : null,
                'sizeReviews' => '/bookings?tab=size',
                'homeRequests' => '/bookings?tab=home',
            ],
        ];

        if ($branch) {
            $bookings = QueueBoard::bookings($branch, $day);

            $payload += [
                'dropOffs' => QueueBoard::dropOffs($branch, $day),
                'sizeReviews' => QueueBoard::sizeReviews($branch),
                'homeRequests' => QueueBoard::homeRequests($branch),
                'columns' => collect(Queue::COLUMNS)->map(fn (array $column, string $key) => [
                    'key' => $key,
                    'label' => $column['label'],
                    'cards' => $bookings->filter(fn (Booking $booking) => QueueBoard::column($booking) === $key)->map($card)->values()->all(),
                ])->values()->all(),
                'arrivals' => QueueBoard::arrivals($branch)->map($card)->all(),
            ];
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }
}
