<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * An open branch that takes 2 units a day, open 8 AM – 6 PM Monday to Saturday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'status' => 'open',
            'city' => $name,
            'address' => fake()->streetAddress(),
            'phone' => '0917'.fake()->numerify('#######'),
            'weekly_hours' => self::hours(),
            'daily_cap' => 2,
            'lead_days' => 1,
            'months_ahead' => 3,
        ];
    }

    /**
     * The same hours Monday to Saturday, closed on Sunday.
     *
     * @return array<string, array{open: string, close: string}|null>
     */
    public static function hours(string $open = '08:00', string $close = '18:00'): array
    {
        $hours = [];

        foreach (range(1, 7) as $day) {
            $hours[(string) $day] = $day === 7 ? null : ['open' => $open, 'close' => $close];
        }

        return $hours;
    }

    public function cap(int $cap): static
    {
        return $this->state(fn () => ['daily_cap' => $cap]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function homeService(array $areas = ['Cainta', 'Antipolo']): static
    {
        return $this->state(fn () => ['offers_home_service' => true, 'home_service_areas' => $areas]);
    }
}
