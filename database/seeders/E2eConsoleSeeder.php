<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * The units the React admin's browser tests move, made when they start
 * (tests/e2e/console-queue.spec.ts) so they don't touch the units the other
 * specs count on. E2E_PROJECT says which browser project they are for.
 */
class E2eConsoleSeeder extends Seeder
{
    public function run(): void
    {
        $project = getenv('E2E_PROJECT') ?: 'desktop';
        $cainta = Branch::query()->where('slug', 'cainta')->firstOrFail();

        foreach (['Console' => 'CON', 'ConsoleLive' => 'CLV'] as $kind => $plate) {
            $name = "E2E {$kind} {$project}";

            // A retry starts from a unit that has not moved yet
            Booking::query()->where('name', $name)->get()->each->delete();
            Booking::factory()->priced()->status('confirmed')->on(today()->toDateString())->create(['branch_id' => $cainta->id, 'name' => $name, 'plate_number' => "{$plate}-{$project}"]);
        }
    }
}
