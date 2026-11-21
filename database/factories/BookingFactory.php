<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * A confirmed onsite reservation for a day a week from now, a one-day
     * job, with a unit the admin hasn't priced yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = CarbonImmutable::today()->addDays(7);

        return [
            'kind' => 'onsite',
            'source' => 'site',
            'status' => 'confirmed',
            'branch_id' => Branch::factory(),
            'drop_off_on' => $day,
            'ready_on' => $day,
            'vehicle_year' => 2021,
            'vehicle_type' => 'sedan',
            'vehicle_model' => fake()->randomElement(['Toyota Vios', 'Honda City', 'Mitsubishi Mirage']),
            'plate_number' => strtoupper(fake()->bothify('???####')),
            'name' => fake()->name(),
            'phone' => '0917'.fake()->numerify('#######'),
            'email' => fake()->safeEmail(),
            'total_days' => 1,
        ];
    }

    public function on(string $dropOff, ?string $readyOn = null): static
    {
        return $this->state(fn () => ['drop_off_on' => CarbonImmutable::parse($dropOff), 'ready_on' => CarbonImmutable::parse($readyOn ?? $dropOff)]);
    }

    /**
     * The admin has seen the unit and priced the booking.
     */
    public function priced(int $total = 5000, string $size = 'Medium'): static
    {
        return $this->state(fn () => [
            'vehicle_size_name' => $size,
            'size_reviewed_at' => now(),
            'priced_at' => now(),
            'total_price' => $total,
        ]);
    }

    public function hold(int $minutesLeft = 30): static
    {
        return $this->state(fn () => ['status' => 'awaiting_payment', 'hold_expires_at' => now()->addMinutes($minutesLeft), 'amount_due' => 500]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function home(): static
    {
        return $this->state(fn () => [
            'kind' => 'home',
            'status' => 'requested',
            'drop_off_on' => null,
            'ready_on' => null,
            'preferred_date' => CarbonImmutable::today()->addDays(5),
            'preferred_window' => 'morning',
            'address' => fake()->streetAddress(),
            'area' => 'Cainta',
        ]);
    }
}
