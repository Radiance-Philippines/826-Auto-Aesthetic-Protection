<?php

namespace Database\Factories;

use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => (string) fake()->unique()->numerify('1##################'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => null,
            'last_signed_in_at' => now(),
        ];
    }

    /**
     * A walk-in or phone customer the team added: no Google account.
     */
    public function walkIn(): static
    {
        return $this->state(fn () => ['google_id' => null, 'email' => null, 'phone' => '0917'.fake()->numerify('#######'), 'source' => 'walk_in']);
    }
}
