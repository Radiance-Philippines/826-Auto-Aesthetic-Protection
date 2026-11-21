<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * SAMPLE data for local testing and the portfolio demo only, never for
 * 826's real site: made-up day estimates, prices per size class, daily caps
 * and hours where those are blank, a few saved units, and one booking in
 * every stage (one waiting for its size review). The real numbers come from
 * the owner. Bookings, units, customers and blocks it makes are marked, and
 * --clear removes only those (sample estimates, prices and hours stay until
 * edited in the admin).
 */
#[Signature('826:demo-data {--clear : Remove the sample bookings, units, customers and blocks} {--force : Run outside local and demo mode}')]
#[Description('Add (or remove) SAMPLE estimates, prices and bookings for testing or a demo')]
class DemoData extends Command
{
    public const SOURCE = 'demo';

    public const GUEST_PREFIX = 'demo-';

    public const BLOCK_PREFIX = 'Sample:';

    /**
     * Made-up starting prices by service, for the Small class; each bigger
     * class adds 20%.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const SAMPLE = [
        'exterior-detailing' => [2500, 1],
        'interior-detailing' => [3000, 1],
        'graphene-coating' => [15000, 2],
        'self-healing-graphene-coating' => [22000, 3],
        'borophene-coating' => [25000, 3],
        'interior-leather-coating' => [5000, 1],
        'full-body-ppf' => [120000, 5],
        'per-panel-ppf' => [8000, 2],
        'windshield-ppf' => [9000, 1],
        'nano-ceramic-tint' => [9000, 1],
    ];

    private const SIZES = ['Small', 'Medium', 'Large', 'XL'];

    public function handle(): int
    {
        if (! app()->isLocal() && ! config('app.demo') && ! $this->option('force')) {
            $this->error('Sample data is for local testing and the demo only. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $this->clear();

        if ($this->option('clear')) {
            $this->info('Sample bookings, units, customers and blocks removed.');

            return self::SUCCESS;
        }

        $branches = $this->openBranches();
        $this->fillEstimatesAndRates();
        $this->bookings($branches[0]);

        $this->info('Sample data added: daily caps and hours on '.collect($branches)->pluck('name')->implode(', ').', day estimates and prices where blank, and bookings at '.$branches[0]->name.'.');
        $this->line('Remove the bookings with: php artisan 826:demo-data --clear');

        return self::SUCCESS;
    }

    private function clear(): void
    {
        Booking::query()->where('source', self::SOURCE)->get()->each->delete();
        $guests = Guest::query()->where('google_id', 'like', self::GUEST_PREFIX.'%');
        Vehicle::query()->whereIn('guest_id', (clone $guests)->select('id'))->delete();
        $guests->delete();
        BranchBlock::query()->where('title', 'like', self::BLOCK_PREFIX.'%')->delete();
    }

    /**
     * @return list<Branch>
     */
    private function openBranches(): array
    {
        $branches = Branch::query()->ordered()->get();

        if ($branches->isEmpty()) {
            $branches = collect([Branch::create(['name' => 'Sample branch', 'slug' => 'sample-branch', 'city' => 'Cainta'])]);
        }

        $hours = [];

        foreach (range(1, 7) as $day) {
            $hours[(string) $day] = $day === 7 ? null : ['open' => '08:00', 'close' => '18:00'];
        }

        $open = [];

        foreach ($branches as $index => $branch) {
            $branch->update(array_filter([
                'weekly_hours' => $branch->hasHours() ? null : $hours,
                'daily_cap' => $branch->dailyCap() > 0 ? null : 4,
                'status' => $branch->status === 'draft' ? ($index < 2 ? 'open' : 'coming_soon') : null,
                'offers_home_service' => $index === 0 ? true : null,
                'home_service_areas' => $index === 0 && blank($branch->home_service_areas) ? ['Cainta', 'Antipolo', 'Taytay', 'Pasig'] : null,
            ], fn ($value) => $value !== null));

            if ($branch->services()->doesntExist()) {
                $branch->services()->sync(Service::query()->pluck('id'));
            }

            if ($branch->status === 'open') {
                $open[] = $branch;
            }
        }

        Service::query()->whereIn('slug', ['exterior-detailing', 'interior-detailing'])->update(['home_service_ok' => true]);

        return $open ?: [$branches->first()];
    }

    /**
     * @return Collection<int, VehicleSize>
     */
    private function sizes(): Collection
    {
        if (VehicleSize::query()->doesntExist()) {
            foreach (self::SIZES as $order => $name) {
                VehicleSize::create(['name' => $name, 'sort_order' => $order]);
            }
        }

        return VehicleSize::query()->ordered()->get();
    }

    private function fillEstimatesAndRates(): void
    {
        $sizes = $this->sizes();

        foreach (Service::query()->get() as $service) {
            [$price, $days] = self::SAMPLE[$service->slug] ?? [5000, 1];

            if (! $service->estimate_days) {
                $service->update(['estimate_days' => $days]);
            }

            foreach ($sizes as $step => $size) {
                ServiceRate::query()->firstOrCreate(
                    ['service_id' => $service->id, 'vehicle_size_id' => $size->id],
                    ['price' => (int) (round($price * (1 + 0.2 * $step) / 100) * 100)],
                );
            }
        }
    }

    private function bookings(Branch $branch): void
    {
        $sizes = $this->sizes();
        $medium = $sizes->get(1) ?? $sizes->first();
        $services = Service::query()->with('category')->get()->keyBy('slug');
        $dropOff = function (int $daysAhead) use ($branch): CarbonImmutable {
            $date = today()->toImmutable()->addDays($daysAhead);

            while (! $branch->opensOn($date)) {
                $date = $date->addDay();
            }

            return $date;
        };

        // name, status, services, drop-off, year, model, type, size class (null: not reviewed)
        $samples = [
            ['Sample Customer A', 'confirmed', ['exterior-detailing'], $dropOff(1), 2021, 'Toyota Vios', 'sedan', $medium],
            ['Sample Customer B', 'arrived', ['interior-detailing'], $dropOff(0), 2019, 'Honda Civic', 'sedan', $medium],
            ['Sample Customer C', 'in_progress', ['graphene-coating'], $dropOff(0)->subDay(), 2022, 'Mitsubishi Montero', 'suv', $sizes->get(2) ?? $medium],
            ['Sample Customer D', 'quality_check', ['nano-ceramic-tint'], $dropOff(0)->subDays(2), 2020, 'Toyota Fortuner', 'suv', $sizes->get(2) ?? $medium],
            ['Sample Customer E', 'ready', ['per-panel-ppf'], $dropOff(0)->subDays(3), 2023, 'Ford Ranger', 'pickup', $sizes->get(3) ?? $medium],
            ['Sample Customer F', 'exited', ['exterior-detailing'], $dropOff(0)->subDays(5), 2018, 'Suzuki Celerio', 'hatchback', $sizes->first()],
            ['Sample Customer G', 'payment_review', ['nano-ceramic-tint'], $dropOff(2), 2017, 'Nissan Navara', 'pickup', $sizes->get(3) ?? $medium],
            ['Sample Customer H', 'awaiting_payment', ['per-panel-ppf'], $dropOff(3), 2016, 'Honda Jazz', 'hatchback', null],
            ['Sample Customer I', 'confirmed', ['full-body-ppf'], $dropOff(4), 2024, 'Toyota Hilux', 'pickup', null],
        ];

        foreach ($samples as [$name, $status, $slugs, $day, $year, $model, $type, $size]) {
            $customer = Guest::create([
                'google_id' => self::GUEST_PREFIX.str($name)->slug(),
                'name' => $name,
                'email' => str($name)->slug().'@example.com',
                'last_signed_in_at' => now(),
            ]);

            $vehicle = Vehicle::create([
                'guest_id' => $customer->id,
                'year' => $year,
                'model' => $model,
                'type' => $type,
                'plate_number' => 'SMP'.random_int(1000, 9999),
                'vehicle_size_id' => $size?->id,
                'size_reviewed_at' => $size ? now() : null,
            ]);

            $chosen = collect($slugs)->map(fn (string $slug) => $services->get($slug))->filter()->values();

            if ($chosen->isEmpty()) {
                continue;
            }

            $days = (int) $chosen->sum('estimate_days');
            $prices = $size ? ServiceRate::query()->where('vehicle_size_id', $size->id)->whereIn('service_id', $chosen->pluck('id'))->pluck('price', 'service_id') : collect();

            $booking = Booking::create([
                'kind' => 'onsite',
                'source' => self::SOURCE,
                'status' => $status,
                'branch_id' => $branch->id,
                'guest_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'drop_off_on' => $day,
                'ready_on' => OpenCalendar::for($branch, $day, $day)->readyOn($day, $days) ?? $day,
                'vehicle_year' => $year,
                'vehicle_model' => $model,
                'vehicle_type' => $type,
                'plate_number' => $vehicle->plate_number,
                'vehicle_size_id' => $size?->id,
                'vehicle_size_name' => $size?->name,
                'size_reviewed_at' => $size ? now() : null,
                'priced_at' => $size ? now() : null,
                'total_price' => $size ? (int) $prices->sum() : null,
                'name' => $name,
                'phone' => '0900 000 '.random_int(1000, 9999),
                'email' => $customer->email,
                'total_days' => $days,
                'amount_due' => in_array($status, ['payment_review', 'awaiting_payment'], true) ? 500 : null,
                'hold_expires_at' => $status === 'awaiting_payment' ? now()->addHours(6) : null,
                'payment_method' => $status === 'payment_review' ? 'gcash' : null,
                'payment_submitted_at' => $status === 'payment_review' ? now()->subMinutes(10) : null,
            ]);

            foreach ($chosen as $index => $service) {
                $booking->items()->create([
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'category_name' => $service->category?->name,
                    'price' => $size ? $prices->get($service->id) : null,
                    'duration_days' => (int) $service->estimate_days,
                    'sort_order' => $index,
                ]);
            }
        }

        $home = $services->get('exterior-detailing');

        if ($home) {
            $customer = Guest::create(['google_id' => self::GUEST_PREFIX.'sample-customer-j', 'name' => 'Sample Customer J', 'email' => 'sample-customer-j@example.com', 'last_signed_in_at' => now()]);
            $vehicle = Vehicle::create(['guest_id' => $customer->id, 'year' => 2020, 'model' => 'Nissan Terra', 'type' => 'suv', 'plate_number' => 'SMP'.random_int(1000, 9999)]);
            $booking = Booking::create([
                'kind' => 'home',
                'source' => self::SOURCE,
                'status' => 'requested',
                'branch_id' => $branch->id,
                'guest_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'preferred_date' => $dropOff(5),
                'preferred_window' => 'morning',
                'address' => 'Sample address',
                'area' => 'Antipolo',
                'has_water' => true,
                'has_power' => true,
                'has_shade' => false,
                'vehicle_year' => 2020,
                'vehicle_model' => 'Nissan Terra',
                'vehicle_type' => 'suv',
                'plate_number' => $vehicle->plate_number,
                'name' => 'Sample Customer J',
                'phone' => '0900 000 1234',
                'email' => $customer->email,
                'total_days' => (int) $home->estimate_days,
            ]);
            $booking->items()->create(['service_id' => $home->id, 'service_name' => $home->name, 'category_name' => $home->category?->name, 'duration_days' => (int) $home->estimate_days]);
        }

        $day = $dropOff(6);
        BranchBlock::create([
            'branch_id' => $branch->id,
            'kind' => 'full',
            'starts_at' => $day->startOfDay(),
            'ends_at' => $day->addDay()->startOfDay(),
            'title' => self::BLOCK_PREFIX.' a big job that day',
        ]);
    }
}
