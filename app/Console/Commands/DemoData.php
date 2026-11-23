<?php

namespace App\Console\Commands;

use App\Models\Backjob;
use App\Models\BackjobType;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchBlock;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Referrals;
use App\Support\Schedule\OpenCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * Marks walk-in sample customers (they have no google_id to mark them by).
     */
    public const GUEST_NOTE = 'Sample data';

    public const BACKJOB_PREFIX = 'Sample:';

    /**
     * Made-up warranty months by service (sample only; the owner sets the real ones).
     *
     * @var array<string, int>
     */
    private const SAMPLE_WARRANTY = [
        'graphene-coating' => 12,
        'self-healing-graphene-coating' => 24,
        'borophene-coating' => 36,
        'interior-leather-coating' => 12,
        'full-body-ppf' => 60,
        'per-panel-ppf' => 36,
        'windshield-ppf' => 24,
        'nano-ceramic-tint' => 36,
    ];

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

    /**
     * Sample staff accounts, by email: name and role.
     *
     * @var array<string, array{string, string}>
     */
    private const STAFF = [
        'sample-operations@826auto.test' => ['Sample operations', 'operations'],
        'sample-sales@826auto.test' => ['Sample sales', 'sales_admin'],
    ];

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
        $this->fillWarrantyAndReferral();
        $this->bookings($branches[0]);
        $this->sampleStaff($branches[0]);

        $this->info('Sample data added: daily caps and hours on '.collect($branches)->pluck('name')->implode(', ').', day estimates and prices where blank, and bookings at '.$branches[0]->name.'.');
        $this->line('Remove the bookings with: php artisan 826:demo-data --clear');

        return self::SUCCESS;
    }

    private function clear(): void
    {
        Booking::query()->where('source', self::SOURCE)->get()->each->delete();
        BackjobType::query()->where('name', 'like', self::BACKJOB_PREFIX.'%')->delete();
        $guests = Guest::query()->where('google_id', 'like', self::GUEST_PREFIX.'%')->orWhere('notes', self::GUEST_NOTE);
        Vehicle::query()->whereIn('guest_id', (clone $guests)->select('id'))->delete();
        $guests->delete();
        BranchBlock::query()->where('title', 'like', self::BLOCK_PREFIX.'%')->delete();
        User::query()->whereIn('email', array_keys(self::STAFF))->delete();
    }

    /**
     * Two clearly marked staff accounts to see the roles with, on a local
     * machine only. Their password is random and never shown: sign in as them
     * by resetting it from the login page, or set one yourself in Users.
     */
    private function sampleStaff(Branch $branch): void
    {
        if (! app()->isLocal()) {
            return;
        }

        foreach (self::STAFF as $email => [$name, $role]) {
            User::firstOrCreate(['email' => $email], ['name' => $name, 'role' => $role, 'password' => Str::random(40)])->branches()->syncWithoutDetaching([$branch->id]);
        }
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

    /**
     * Sample warranty months where blank, sample backjob types, and a
     * sample referral discount if the owner hasn't set one.
     */
    private function fillWarrantyAndReferral(): void
    {
        foreach (Service::query()->whereNull('warranty_months')->get() as $service) {
            if (isset(self::SAMPLE_WARRANTY[$service->slug])) {
                $service->update(['warranty_months' => self::SAMPLE_WARRANTY[$service->slug]]);
            }
        }

        $types = [
            'Coating peeling' => ['graphene-coating', 'self-healing-graphene-coating', 'borophene-coating', 'interior-leather-coating'],
            'Tint bubbling' => ['nano-ceramic-tint'],
            'PPF lifting' => ['full-body-ppf', 'per-panel-ppf', 'windshield-ppf'],
        ];

        foreach (array_keys($types) as $order => $name) {
            $type = BackjobType::query()->firstOrCreate(['name' => self::BACKJOB_PREFIX.' '.$name], ['sort_order' => $order]);
            $type->services()->syncWithoutDetaching(Service::query()->whereIn('slug', $types[$name])->pluck('id'));
        }

        $referral = ReferralSetting::instance();

        if (! $referral->enabled && ! $referral->hasDiscount()) {
            $referral->update(['enabled' => true, 'discount_type' => 'fixed', 'discount_value' => 500, 'owner_points' => 100]);
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
                'branch_id' => $branch->id,
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
                    'warranty_months' => $service->warranty_months,
                    'sort_order' => $index,
                ]);
            }

            if ($status === 'exited') {
                $booking->update(['exited_at' => $day->addDay()->setTime(12, 0)]);
            }
        }

        $this->walkIn($branch, $services, $sizes);

        $home = $services->get('exterior-detailing');

        if ($home) {
            $customer = Guest::create(['google_id' => self::GUEST_PREFIX.'sample-customer-j', 'name' => 'Sample Customer J', 'email' => 'sample-customer-j@example.com', 'last_signed_in_at' => now(), 'branch_id' => $branch->id]);
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

        $this->demoCustomer($branch, $services, $sizes, $dropOff);

        $day = $dropOff(6);
        BranchBlock::create([
            'branch_id' => $branch->id,
            'kind' => 'full',
            'starts_at' => $day->startOfDay(),
            'ends_at' => $day->addDay()->startOfDay(),
            'title' => self::BLOCK_PREFIX.' a big job that day',
        ]);
    }

    /**
     * The customer the demo and local "Sign in" buttons use (website and
     * app): two saved units, one unit in the shop, one booked ahead, one
     * finished job under warranty (not yet reviewed), a referral code, and
     * two friends who used it (one already awarded). Prices come from the
     * sample rates above.
     *
     * @param  Collection<string, Service>  $services
     * @param  Collection<int, VehicleSize>  $sizes
     */
    private function demoCustomer(Branch $branch, Collection $services, Collection $sizes, \Closure $dropOff): void
    {
        $size = $sizes->get(1) ?? $sizes->first();
        $branch = Branch::query()->where('slug', 'cainta')->first() ?? $branch;
        $customer = Guest::demoCustomer();
        $customer->update(['branch_id' => $branch->id, 'phone' => '0900 000 7777']);
        Referrals::codeFor($customer);

        $units = [
            ['year' => 2021, 'model' => 'Toyota Vios', 'type' => 'sedan'],
            ['year' => 2023, 'model' => 'Honda City', 'type' => 'sedan'],
        ];
        $vehicles = collect($units)->map(fn (array $unit) => Vehicle::create([
            ...$unit,
            'guest_id' => $customer->id,
            'plate_number' => 'SMP'.random_int(1000, 9999),
            'vehicle_size_id' => $size?->id,
            'size_reviewed_at' => $size ? now() : null,
        ]))->values();

        // The shop is closed on some days: a unit only arrives on an open one
        $openDay = function (CarbonImmutable $date) use ($branch): CarbonImmutable {
            while (! $branch->opensOn($date)) {
                $date = $date->subDay();
            }

            return $date;
        };

        // status, service, drop-off, exited, unit
        $jobs = [
            ['in_progress', 'graphene-coating', $openDay($dropOff(0)->subDay()), null, 0],
            ['confirmed', 'exterior-detailing', $dropOff(2), null, 1],
            ['exited', 'graphene-coating', $openDay($dropOff(0)->subDays(7)), today()->toImmutable()->subDays(4)->setTime(12, 0), 1],
        ];

        foreach ($jobs as [$status, $slug, $day, $exited, $unit]) {
            $service = $services->get($slug);

            if (! $service || ! $size) {
                continue;
            }

            $vehicle = $vehicles[$unit];
            $price = (int) ServiceRate::query()->where('service_id', $service->id)->where('vehicle_size_id', $size->id)->value('price');

            // Booked a few days before the drop-off; arrives in working hours, after it was booked
            $booking = Booking::unguarded(fn () => Booking::create([
                ...($status === 'confirmed' ? [] : ['created_at' => $this->bookedAt($day)]),
                'kind' => 'onsite',
                'source' => self::SOURCE,
                'status' => $status,
                'branch_id' => $branch->id,
                'guest_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'drop_off_on' => $day,
                'ready_on' => OpenCalendar::for($branch, $day, $day)->readyOn($day, max(1, (int) $service->estimate_days)) ?? $day,
                'vehicle_year' => $vehicle->year,
                'vehicle_model' => $vehicle->model,
                'vehicle_type' => $vehicle->type,
                'plate_number' => $vehicle->plate_number,
                'vehicle_size_id' => $size->id,
                'vehicle_size_name' => $size->name,
                'size_reviewed_at' => now(),
                'priced_at' => now(),
                'total_price' => $price,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'total_days' => (int) $service->estimate_days,
                'arrived_at' => $status === 'confirmed' ? null : $this->arrivedAt($day),
                'exited_at' => $exited,
            ]));
            $booking->items()->create(['service_id' => $service->id, 'service_name' => $service->name, 'category_name' => $service->category?->name, 'price' => $price, 'duration_days' => (int) $service->estimate_days, 'warranty_months' => $service->warranty_months]);
        }

        // Two friends used the code: the finished job earned its points, the booked one is pending
        foreach (['Sample Customer F', 'Sample Customer A'] as $friend) {
            $booking = Booking::query()->where('source', self::SOURCE)->where('name', $friend)->first();

            if ($booking) {
                Referrals::redeem($booking, $customer);
                Referrals::syncStatus($booking);
            }
        }
    }

    /** A unit arrives at 9:40 in the morning of its drop-off day (never a bare date at midnight) */
    private function arrivedAt(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTime(9, 40);
    }

    /** The booking was made three days before the drop-off, in the afternoon */
    private function bookedAt(CarbonImmutable $day): CarbonImmutable
    {
        return $day->subDays(3)->setTime(14, 25);
    }

    /**
     * A sample backjob on a finished sample booking, if a sample type covers its service.
     */
    private function sampleBackjob(Booking $booking): void
    {
        $item = $booking->items()->first();
        $type = $item?->service?->backjobTypes()->where('name', 'like', self::BACKJOB_PREFIX.'%')->first();

        if ($item && $type) {
            Backjob::create(['booking_id' => $booking->id, 'booking_item_id' => $item->id, 'backjob_type_id' => $type->id, 'branch_id' => $booking->branch_id, 'noted_on' => today(), 'note' => 'Sample backjob', 'status' => 'open']);
        }
    }

    /**
     * A sample walk-in customer (no Google account) with a finished job that
     * paid with a referral code from Sample Customer A's code.
     *
     * @param  Collection<string, Service>  $services
     * @param  Collection<int, VehicleSize>  $sizes
     */
    private function walkIn(Branch $branch, Collection $services, Collection $sizes): void
    {
        $service = $services->get('graphene-coating') ?? $services->first();
        $size = $sizes->get(1) ?? $sizes->first();

        if (! $service || ! $size) {
            return;
        }

        $customer = Guest::create(['name' => 'Sample Walk-in Customer', 'phone' => '0900 000 5555', 'source' => 'walk_in', 'branch_id' => $branch->id, 'notes' => self::GUEST_NOTE]);
        Referrals::codeFor($customer);
        $vehicle = Vehicle::create(['guest_id' => $customer->id, 'year' => 2022, 'model' => 'Toyota Raize', 'type' => 'suv', 'plate_number' => 'SMP'.random_int(1000, 9999), 'vehicle_size_id' => $size->id, 'size_reviewed_at' => now()]);
        $price = (int) ServiceRate::query()->where('service_id', $service->id)->where('vehicle_size_id', $size->id)->value('price');
        $exited = today()->toImmutable()->subDays(10)->setTime(12, 0);

        $booking = Booking::unguarded(fn () => Booking::create([
            'created_at' => $this->bookedAt($exited->subDays(2)),
            'kind' => 'onsite',
            'source' => self::SOURCE,
            'status' => 'exited',
            'branch_id' => $branch->id,
            'guest_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'drop_off_on' => $exited->subDays(2),
            'ready_on' => $exited,
            'vehicle_year' => 2022,
            'vehicle_model' => 'Toyota Raize',
            'vehicle_type' => 'suv',
            'plate_number' => $vehicle->plate_number,
            'vehicle_size_id' => $size->id,
            'vehicle_size_name' => $size->name,
            'size_reviewed_at' => now(),
            'priced_at' => now(),
            'total_price' => $price,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'total_days' => (int) $service->estimate_days,
            'arrived_at' => $this->arrivedAt($exited->subDays(2)),
            'exited_at' => $exited,
        ]));
        $booking->items()->create(['service_id' => $service->id, 'service_name' => $service->name, 'category_name' => $service->category?->name, 'price' => $price, 'duration_days' => (int) $service->estimate_days, 'warranty_months' => $service->warranty_months]);
        $this->sampleBackjob($booking);
    }
}
