<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\ReferralSetting;
use App\Models\RewardRedemption;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleSize;
use App\Support\ManualTransaction;
use App\Support\Referrals;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Extra sample data for the review screenshots (tests/e2e/review-evidence.spec.ts), added by
 * E2eSeeder only when REVIEW_DIR is set: referral use on finished jobs, redeemed points, a unit
 * with service history, and one job at another branch the Cainta team must not see.
 * Everything goes through the same code the admin uses (ManualTransaction). SAMPLE data, testing only.
 */
class ReviewEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'admin@826auto.test')->firstOrFail();
        $sales = User::query()->where('email', 'sales@826auto.test')->firstOrFail();
        $cainta = Branch::query()->where('slug', 'cainta')->firstOrFail();
        $other = Branch::query()->where('id', '!=', $cainta->id)->orderBy('id')->firstOrFail();
        $other->services()->sync(Service::query()->pluck('id'));
        $size = VehicleSize::query()->ordered()->skip(1)->firstOrFail();
        $services = Service::query()->orderBy('sort_order')->orderBy('id')->take(3)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $prices = collect($services)->mapWithKeys(fn (string $id) => [$id => 2000])->all();

        ReferralSetting::instance()->update(['enabled' => true, 'discount_type' => 'fixed', 'discount_value' => 500, 'owner_points' => 100, 'peso_per_point' => 5]);

        $referrer = $this->customer('Maria Referrer', '0917 100 0001', $cainta, 'MARIA-7K3Q', $sales);
        $outsider = $this->customer('Olivia Outsider', '0917 100 0002', $other, 'OLIVIA-2B4C');
        $this->customer('Fresh Friend', '0917 100 0003', $cainta, null, $sales);

        $job = fn (Branch $branch, Guest $guest, array $overrides) => ManualTransaction::record($owner, [
            'branch_id' => $branch->id,
            'guest_id' => $guest->id,
            'vehicle_size_id' => $size->id,
            'services' => array_slice($services, 0, $overrides['count'] ?? 2),
            'prices' => $prices,
            'status' => 'exited',
            ...array_diff_key($overrides, ['count' => 1]),
        ]);
        $today = CarbonImmutable::today();

        // Cainta: two friends used Maria's code on finished jobs, a third is still in the shop (pending, no points yet)
        foreach ([['Juan Friend', '0917 200 0001', 3, 'JUA 1001'], ['Ana Friend', '0917 200 0002', 1, 'ANA 1002']] as [$name, $phone, $daysAgo, $plate]) {
            $friend = $this->customer($name, $phone, $cainta, null, $sales);
            $job($cainta, $friend, ['service_date' => $today->subDays($daysAgo)->toDateString(), 'vehicle_year' => 2021, 'vehicle_model' => 'Toyota Vios', 'vehicle_type' => 'sedan', 'plate_number' => $plate, 'referral_code' => $referrer->referral_code, 'amount_paid' => 3500, 'payment_method' => 'cash']);
        }

        $waiting = $this->customer('Pending Friend', '0917 200 0003', $cainta, null, $sales);
        $job($cainta, $waiting, ['service_date' => $today->toDateString(), 'status' => 'in_progress', 'vehicle_year' => 2019, 'vehicle_model' => 'Honda Civic', 'vehicle_type' => 'sedan', 'plate_number' => 'PEN 1003', 'referral_code' => $referrer->referral_code]);

        // Maria spent some of her points
        RewardRedemption::create(['guest_id' => $referrer->id, 'points' => 50, 'peso_value' => 250, 'note' => 'Sample: used for a discount', 'created_by' => $owner->id]);

        // Another branch: Olivia's code used on a finished job there (the Cainta team must not see it)
        $stranger = $this->customer('Pedro Stranger', '0917 300 0001', $other, null);
        $job($other, $stranger, ['service_date' => $today->subDays(2)->toDateString(), 'vehicle_year' => 2018, 'vehicle_model' => 'Mitsubishi Mirage', 'vehicle_type' => 'hatchback', 'plate_number' => 'STR 1004', 'referral_code' => $outsider->referral_code]);

        // The unit with history: E2E Customer's Honda City, two finished jobs at Cainta, one at the other branch, one booked ahead
        $history = Guest::query()->where('name', 'E2E Customer')->firstOrFail();
        $unit = ['vehicle_year' => 2020, 'vehicle_model' => 'Honda City', 'vehicle_type' => 'sedan', 'plate_number' => 'CRM1234'];
        $job($cainta, $history, [...$unit, 'service_date' => $today->subDays(20)->toDateString(), 'count' => 1]);
        $job($cainta, $history, [...$unit, 'service_date' => $today->subDays(6)->toDateString(), 'count' => 3]);
        $job($other, $history, [...$unit, 'service_date' => $today->subDays(4)->toDateString(), 'count' => 2]);
        $job($cainta, $history, [...$unit, 'service_date' => $today->addDays(2)->toDateString(), 'status' => 'confirmed', 'count' => 2]);

        Vehicle::query()->where('plate_number', 'CRM1234')->update(['vehicle_size_id' => $size->id, 'size_reviewed_at' => now()]);
    }

    private function customer(string $name, string $phone, Branch $branch, ?string $code, ?User $rep = null): Guest
    {
        $guest = Guest::factory()->walkIn()->create(['name' => $name, 'phone' => $phone, 'branch_id' => $branch->id, 'sales_rep_id' => $rep?->id]);

        if ($code) {
            $guest->forceFill(['referral_code' => $code])->save();
        } else {
            Referrals::codeFor($guest);
        }

        return $guest->refresh();
    }
}
