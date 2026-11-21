<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsBookingInput;
use App\Models\Booking;
use App\Models\Review;
use App\Support\ImagePipeline;
use App\Support\Notifier;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A customer's review of a finished booking: only its signed-in customer,
 * only once. It waits for the team's approval before the site shows it.
 */
class ReviewController extends Controller
{
    use ReadsBookingInput;

    /**
     * POST /api/my-bookings/{booking}/review
     */
    public function store(Request $request, int $booking): JsonResponse
    {
        $guest = $this->guest();
        abort_unless($guest, 401);

        $record = Booking::with('review')->find($booking);
        abort_unless($record && $record->guest_id === $guest->getKey(), 404);

        if ($record->status !== 'exited') {
            return response()->json(['message' => 'You can review a booking once your unit has left the shop.'], 409);
        }

        if ($record->review !== null) {
            return response()->json(['message' => 'You already reviewed this booking.'], 409);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:10', 'max:1000'],
            'photo' => ['bail', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192', function (string $attribute, mixed $value, Closure $fail) {
                $size = @getimagesize($value->getRealPath());

                if (! $size || $size[0] * $size[1] > ImagePipeline::MAX_PIXELS) {
                    $fail('We couldn’t read that picture. Try a JPG or PNG.');
                }
            }],
        ], [
            'rating.required' => 'Pick a star rating.',
            'rating.between' => 'Pick a star rating.',
            'rating.integer' => 'Pick a star rating.',
            'body.required' => 'Tell us a little about how it went.',
            'body.min' => 'Write at least 10 characters.',
            'body.max' => 'Keep it under 1,000 characters.',
            'photo.mimes' => 'Use a JPG, PNG or WebP picture.',
            'photo.max' => 'That picture is too big. Use one under 8 MB.',
            'photo.uploaded' => 'That picture didn’t upload. Try again, or use a smaller one.',
        ]);

        if (config('app.demo')) {
            return response()->json(['ok' => true, 'demo' => true]);
        }

        $path = $request->file('photo')?->store('reviews', 'public');

        try {
            $review = Review::create([
                'guest_id' => $guest->getKey(),
                'booking_id' => $record->getKey(),
                'branch_id' => $record->branch_id,
                'rating' => (int) $data['rating'],
                'body' => trim($data['body']),
                'photo' => $path ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            return response()->json(['message' => 'You already reviewed this booking.'], 409);
        }

        Notifier::team($record, 'review');

        return response()->json(['ok' => true, 'review' => $review->toCustomer()], 201);
    }
}
