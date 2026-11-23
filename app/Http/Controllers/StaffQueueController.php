<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Queue;
use App\Models\Booking;
use App\Models\BookingPhoto;
use App\Models\Branch;
use App\Support\ImagePipeline;
use App\Support\QueueBoard;
use App\Support\Reservations;
use App\Support\Schedule\Availability;
use App\Support\Schedule\OpenCalendar;
use App\Support\Staff\StaffViews;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The staff app's queue and unit pages: the same board the admin Queue page
 * shows, one unit's detail, and what the team does to a unit (next step,
 * ready date, notes, photos). Moves go through Reservations::advance, so
 * timestamps, history, customer emails and referral points are the admin's.
 */
class StaffQueueController extends StaffController
{
    /**
     * GET /api/staff/queue?branch=&date=
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireArea('bookings.view', 'queue.update');

        $user = $this->user();
        $branches = Branch::query()->visibleTo($user)->ordered()->get();
        $branch = $this->pickedBranch($request) ?? $branches->first();
        $date = $request->query('date');
        $day = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? CarbonImmutable::parse($date)->startOfDay() : CarbonImmutable::today();

        $payload = [
            'branch' => $branch ? ['id' => $branch->getKey(), 'name' => $branch->name] : null,
            'branches' => $branches->map(fn (Branch $each) => ['id' => $each->getKey(), 'name' => $each->name])->values()->all(),
            'date' => $day->toDateString(),
            'heading' => $day->format('l, M j, Y'),
            'isToday' => $day->isToday(),
        ];

        if (! $branch) {
            return response()->json($payload + ['dropOffs' => null, 'columns' => [], 'counts' => null, 'needsSize' => null, 'homeRequests' => null]);
        }

        $cap = $branch->dailyCap();
        $bookings = QueueBoard::bookings($branch, $day);
        $calendar = OpenCalendar::for($branch, CarbonImmutable::today()->subDays(OpenCalendar::MAX_WALK_DAYS), CarbonImmutable::today());

        $columns = collect(Queue::COLUMNS)->map(function (array $column, string $key) use ($bookings, $user, $calendar) {
            $cards = $bookings
                ->filter(fn (Booking $booking) => QueueBoard::column($booking) === $key)
                ->map(fn (Booking $booking) => StaffViews::card($booking, $user, $calendar))
                ->values();

            return ['key' => $key, 'label' => $column['label'], 'count' => $cards->count(), 'cards' => $cards->all()];
        })->values();

        $sales = StaffViews::seesMoney($user);

        return response()->json($payload + [
            'dropOffs' => [
                'cap' => $cap,
                'booked' => Availability::unitsOn($branch, $day),
                'state' => $cap > 0 ? Availability::check($branch, $day, byStaff: true) : 'closed',
            ],
            'columns' => $columns->all(),
            'counts' => ['all' => $bookings->count(), ...$columns->pluck('count', 'key')->all()],
            'needsSize' => $sales ? Booking::query()->where('branch_id', $branch->id)->needsSizeReview()->count() : null,
            'homeRequests' => $sales ? Booking::query()->where('branch_id', $branch->id)->where('kind', 'home')->where('status', 'requested')->count() : null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * GET /api/staff/bookings/{id}
     */
    public function show(int $id): JsonResponse
    {
        $this->requireArea('bookings.view', 'queue.update');

        return response()->json(StaffViews::detail($this->visibleBooking($id), $this->user()))->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/staff/bookings/{id}/stage {to}: only the unit's next step
     * (no skips: the same one-step rule as the admin Queue).
     */
    public function stage(Request $request, int $id): JsonResponse
    {
        $this->requireArea('queue.update');

        $data = $request->validate(['to' => ['required', 'in:arrived,in_progress,quality_check,ready,exited']]);
        $booking = $this->visibleBooking($id);

        $error = DB::transaction(function () use ($booking, $data) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());
            $next = $booking->nextStatus();

            if ($next === null) {
                return 'This unit can’t move on from “'.(Booking::STATUSES[$booking->status] ?? $booking->status).'”.';
            }

            if ($next !== $data['to']) {
                return 'This unit is “'.Booking::STATUSES[$booking->status].'”. Its next step is “'.Booking::STATUSES[$next].'”.';
            }

            Reservations::advance($booking);

            return null;
        });

        if ($error !== null) {
            return response()->json(['message' => $error, 'errors' => ['to' => [$error]]], 422);
        }

        return response()->json(StaffViews::detail($booking->refresh(), $this->user()));
    }

    /**
     * POST /api/staff/bookings/{id}/ready-date {date}
     */
    public function readyDate(Request $request, int $id): JsonResponse
    {
        $this->requireArea('queue.update');

        $booking = $this->visibleBooking($id);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.($booking->drop_off_on?->toDateString() ?? '1970-01-01')],
        ], [
            'date.after_or_equal' => 'The ready date can’t be before the drop-off day.',
            'date.date_format' => 'Pick a date.',
        ]);

        if (! $booking->canChangeReadyDate()) {
            return response()->json(['message' => 'The ready date can’t be changed for this unit.'], 422);
        }

        Reservations::changeReadyDate($booking, CarbonImmutable::parse($data['date']));

        return response()->json(StaffViews::detail($booking->refresh(), $this->user()));
    }

    /**
     * POST /api/staff/bookings/{id}/notes {body}: a line in the booking's history.
     */
    public function note(Request $request, int $id): JsonResponse
    {
        Gate::forUser($this->user())->authorize('update', $booking = $this->visibleBooking($id));

        $data = $request->validate(['body' => ['required', 'string', 'max:500']], [
            'body.required' => 'Write the note first.',
            'body.max' => 'Keep the note under 500 characters.',
        ]);

        $booking->events()->create(['user_id' => $this->user()->getKey(), 'kind' => 'note', 'note' => trim($data['body'])]);

        return response()->json(StaffViews::detail($booking->refresh(), $this->user()), 201);
    }

    /**
     * POST /api/staff/bookings/{id}/photos (multipart "photo": jpg, png or webp, up to 8 MB)
     */
    public function photo(Request $request, int $id): JsonResponse
    {
        $this->requireArea('queue.update');

        $booking = $this->visibleBooking($id);

        $request->validate([
            'photo' => ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192', function (string $attribute, mixed $value, Closure $fail) {
                $size = @getimagesize($value->getRealPath());

                if (! $size || $size[0] * $size[1] > ImagePipeline::MAX_PIXELS) {
                    $fail('We couldn’t read that picture. Try a JPG or PNG.');
                }
            }],
        ], [
            'photo.required' => 'Pick a photo.',
            'photo.mimes' => 'Use a JPG, PNG or WebP picture.',
            'photo.max' => 'That picture is too big. Use one under 8 MB.',
            'photo.uploaded' => 'That picture didn’t upload. Try again, or use a smaller one.',
        ]);

        if ($booking->photos()->count() >= BookingPhoto::MAX_PER_BOOKING) {
            return response()->json(['message' => 'This unit already has '.BookingPhoto::MAX_PER_BOOKING.' photos. That’s the limit.'], 422);
        }

        $pipeline = app(ImagePipeline::class);
        $source = $request->file('photo')->store("booking-photos/{$booking->getKey()}", 'public');

        try {
            $master = $pipeline->normalize($source);
            $pipeline->buildRenditions($master);
        } catch (RuntimeException) {
            $pipeline->deleteFile($source);

            return response()->json(['message' => 'We couldn’t read that picture. Try a JPG or PNG.', 'errors' => ['photo' => ['We couldn’t read that picture. Try a JPG or PNG.']]], 422);
        }

        if ($master !== $source) {
            $pipeline->deleteFile($source);
        }

        $booking->photos()->create(['user_id' => $this->user()->getKey(), 'path' => $master]);

        return response()->json(StaffViews::detail($booking->refresh(), $this->user()), 201);
    }

    /**
     * GET /api/staff/bookings/{id}/proof: the payment screenshot, for the sales side of the branch.
     */
    public function proof(int $id): StreamedResponse
    {
        $this->requireArea('bookings.sales');

        return MyBookingsController::streamProof($this->visibleBooking($id));
    }
}
