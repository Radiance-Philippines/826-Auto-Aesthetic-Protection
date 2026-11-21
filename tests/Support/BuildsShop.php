<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceRate;
use App\Models\SiteSetting;
use App\Models\VehicleSize;

/**
 * A branch ready for online bookings: open Monday to Saturday with a daily
 * cap of 2, a Medium size class, and two services (a 1-day detail and a
 * 3-day coating) with a price for that class, with Google sign-in set up.
 */
trait BuildsShop
{
    protected Branch $branch;

    protected VehicleSize $medium;

    protected Service $detail;

    protected Service $coating;

    protected function buildShop(int $cap = 2): void
    {
        $this->branch = Branch::factory()->cap($cap)->create(['name' => 'Cainta', 'slug' => 'cainta']);
        $this->medium = VehicleSize::factory()->create(['name' => 'Medium']);

        $detailing = ServiceCategory::factory()->create(['name' => 'Detailing', 'sort_order' => 1]);
        $coatings = ServiceCategory::factory()->create(['name' => 'Coating', 'sort_order' => 2]);

        $this->detail = Service::factory()->homeServiceOk()->takes(1)->create(['name' => 'Exterior detailing', 'service_category_id' => $detailing->id]);
        $this->coating = Service::factory()->takes(3)->create(['name' => 'Graphene coating', 'service_category_id' => $coatings->id]);

        ServiceRate::factory()->create(['service_id' => $this->detail->id, 'vehicle_size_id' => $this->medium->id, 'price' => 2500]);
        ServiceRate::factory()->create(['service_id' => $this->coating->id, 'vehicle_size_id' => $this->medium->id, 'price' => 18000]);

        $this->branch->services()->sync([$this->detail->id, $this->coating->id]);

        // Customers sign in with Google to reserve
        SiteSetting::instance()->update(['google_client_id' => 'client.apps.googleusercontent.com', 'google_client_secret' => 'secret']);
    }

    /**
     * A reservation as the site sends it (a new unit, so it waits for its size review).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function reservation(array $overrides = []): array
    {
        return [
            'branch' => $this->branch->id,
            'services' => [$this->detail->id],
            'date' => '2026-11-17',
            'name' => 'Maria Santos',
            'phone' => '0917 123 4567',
            'email' => 'maria@example.com',
            'vehicle_year' => 2021,
            'vehicle_model' => 'Toyota Vios',
            'vehicle_type' => 'sedan',
            'plate_number' => 'abc 1234',
            'consent' => true,
            ...$overrides,
        ];
    }
}
