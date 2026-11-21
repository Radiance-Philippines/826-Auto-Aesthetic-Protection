<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * A unit that hasn't been seen by the admin yet (no size class).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => fake()->numberBetween(2008, 2026),
            'model' => fake()->randomElement(['Toyota Vios', 'Honda City', 'Mitsubishi Mirage']),
            'type' => 'sedan',
            'plate_number' => strtoupper(fake()->unique()->bothify('???####')),
        ];
    }

    public function reviewed(?VehicleSize $size = null): static
    {
        return $this->state(fn () => [
            'vehicle_size_id' => $size?->id ?? VehicleSize::factory(),
            'size_reviewed_at' => now(),
        ]);
    }
}
