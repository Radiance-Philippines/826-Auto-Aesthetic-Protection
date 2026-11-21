<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * A pending review of an exited booking by its customer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $guest = Guest::factory();

        return [
            'guest_id' => $guest,
            'booking_id' => fn (array $attributes) => Booking::factory()->status('exited')->create(['guest_id' => $attributes['guest_id']])->id,
            'rating' => 5,
            'body' => fake()->sentence(12),
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved']);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => 'rejected']);
    }
}
