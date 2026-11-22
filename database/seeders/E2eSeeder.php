<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\MailSetting;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\Access;
use App\Support\Schedule\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A known shop for the browser tests (tests/e2e): the regular seed, then
 * Cainta open Monday to Saturday taking ONE unit a day, every service with
 * a day estimate and prices, and the second open day already full.
 * SAMPLE numbers for testing only, never run on the live site.
 *
 * It also seeds the team for the admin tests (tests/e2e/admin*.spec.ts): one
 * account per role (all with the password "password"), one super admin with
 * an authenticator app, a saved mailbox (mail is captured in a file, never
 * sent), a customer with a unit, and today's bookings: a set per browser
 * project ("desktop", "phone") because the tests change them.
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

        $this->seedTeam($cainta);
        $this->seedMailbox();
        $this->seedCustomer($cainta);
        $this->seedTodaysBookings($cainta);
    }

    /**
     * The base32 secret of the two-factor admin; tests/e2e/admin-support.ts makes the codes from it.
     */
    public const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

    private function seedTeam(Branch $cainta): void
    {
        $team = [
            'twofa@826auto.test' => ['Two Factor Admin', Access::SUPER_ADMIN],
            'emailfa@826auto.test' => ['Email Code Admin', Access::SUPER_ADMIN],
            'operations@826auto.test' => ['Head Detailer', Access::OPERATIONS],
            'sales@826auto.test' => ['Sales Admin', Access::SALES_ADMIN],
            'manager@826auto.test' => ['Branch Manager', Access::BRANCH_MANAGER],
        ];

        foreach ($team as $email => [$name, $role]) {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'role' => $role]);

            if ($role !== Access::SUPER_ADMIN) {
                $user->branches()->sync([$cainta->id]);
            }

            if ($email === 'emailfa@826auto.test') {
                $user->forceFill(['has_email_authentication' => true])->save();
            }

            if ($email === 'twofa@826auto.test') {
                $user->forceFill(['app_authentication_secret' => self::TWO_FACTOR_SECRET])->save();
            }
        }
    }

    private function seedMailbox(): void
    {
        MailSetting::instance()->update([
            'address' => 'shop@826auto.test',
            'password' => 'not-a-real-password',
            'from_name' => '826 E2E',
            'notify_email' => 'alerts@826auto.test',
        ]);
    }

    private function seedCustomer(Branch $cainta): void
    {
        $customer = Guest::factory()->walkIn()->create(['name' => 'E2E Customer', 'branch_id' => $cainta->id]);

        Vehicle::factory()->create(['guest_id' => $customer->id, 'year' => 2020, 'model' => 'Honda City', 'type' => 'sedan', 'plate_number' => 'CRM1234']);
    }

    /**
     * Today at Cainta, one set per browser project: a unit to arrive, one in the
     * queue for the head detailer, one ready to leave, one to cancel, one only read (never changed).
     */
    private function seedTodaysBookings(Branch $cainta): void
    {
        $today = CarbonImmutable::today();

        foreach (['desktop', 'phone'] as $project) {
            $units = [
                'arrive' => ['confirmed', "E2E Arrive {$project}", "ARR-{$project}"],
                'queue' => ['confirmed', "E2E Queue {$project}", "QUE-{$project}"],
                'exit' => ['ready', "E2E Exit {$project}", "EXT-{$project}"],
                'cancel' => ['confirmed', "E2E Cancel {$project}", "CAN-{$project}"],
                'roles' => ['confirmed', "E2E Roles {$project}", "ROL-{$project}"],
            ];

            foreach ($units as [$status, $name, $plate]) {
                Booking::factory()->priced()->status($status)->on($today->toDateString())->create([
                    'branch_id' => $cainta->id,
                    'name' => $name,
                    'plate_number' => $plate,
                ]);
            }
        }
    }
}
