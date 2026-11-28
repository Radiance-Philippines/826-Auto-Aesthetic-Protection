<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\Seeder;

/**
 * The account tests/e2e/console-profile.spec.ts changes (password, two-factor,
 * passkeys): one per browser project, put back to a plain one on every run.
 * For the browser tests only.
 */
class E2eProfileSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'profile-'.preg_replace('/[^a-z]/', '', (string) getenv('E2E_PROJECT')).'@826auto.test';
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['name' => 'Profile Tester', 'password' => 'password', 'role' => Access::OPERATIONS])->save();
        $user->resetTwoFactor();
        $user->passkeys()->delete();
        $user->branches()->sync([Branch::query()->where('slug', 'cainta')->firstOrFail()->id]);
    }
}
