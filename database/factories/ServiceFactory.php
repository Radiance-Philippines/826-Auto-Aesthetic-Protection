<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'service_category_id' => ServiceCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'summary' => fake()->sentence(),
            'sort_order' => 0,
            'is_active' => true,
            'home_service_ok' => false,
            'estimate_days' => 1,
        ];
    }

    public function takes(int $days): static
    {
        return $this->state(fn () => ['estimate_days' => $days]);
    }

    public function homeServiceOk(): static
    {
        return $this->state(fn () => ['home_service_ok' => true]);
    }
}
