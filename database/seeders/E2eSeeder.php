<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\VehicleSize;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A known shop for the browser tests (tests/e2e): the regular seed, then
 * Cainta open Monday to Saturday taking ONE unit a day, every service with
 * a day estimate and prices, and the second open day already full.
 * SAMPLE numbers for testing only, never run on the live site.
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $hours = [];

        foreach (range(1, 7) as $day) {
            $hours[(string) $day] = $day === 7 ? null : ['open' => '08:00', 'close' => '18:00'];
        }

        $cainta = Branch::query()->where('slug', 'cainta')->firstOrFail();
        $cainta->update(['status' => 'open', 'weekly_hours' => $hours, 'daily_cap' => 1, 'lead_days' => 1, 'months_ahead' => 3, 'phone' => '0917 000 0000', 'offers_home_service' => true, 'home_service_areas' => ['Cainta', 'Antipolo']]);
        $cainta->services()->sync(Service::query()->pluck('id'));

        // The other branches stay drafts (not listed); the sample prices below are for testing only
        $sizes = VehicleSize::query()->ordered()->get();

        foreach (Service::query()->get() as $service) {
            $service->update(['estimate_days' => 1]);

            foreach ($sizes as $step => $size) {
                ServiceRate::query()->firstOrCreate(
                    ['service_id' => $service->id, 'vehicle_size_id' => $size->id],
                    ['price' => 2500 + 500 * $step],
                );
            }
        }

        $open = collect(range(0, 20))
            ->map(fn (int $ahead) => CarbonImmutable::today()->addDays($ahead))
            ->filter(fn (CarbonImmutable $date) => Availability::check($cainta, $date) === 'ok')
            ->values();

        Booking::factory()->on($open[1]->toDateString())->create([
            'branch_id' => $cainta->id,
            'name' => 'E2E Full Day',
            'plate_number' => 'FULL1234',
        ]);
    }
}
