<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\VehicleSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRate>
 */
class ServiceRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'vehicle_size_id' => VehicleSize::factory(),
            'price' => 5000,
        ];
    }
}
