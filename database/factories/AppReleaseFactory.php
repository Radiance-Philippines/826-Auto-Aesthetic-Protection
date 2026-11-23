<?php

namespace Database\Factories;

use App\Models\AppRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppRelease>
 */
class AppReleaseFactory extends Factory
{
    /**
     * A published release whose file is made by the test.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->numberBetween(1, 9999);

        return [
            'version' => '0.1.'.$code,
            'version_code' => $code,
            'apk_path' => "apks/826-auto-{$code}.apk",
            'size' => 1024,
            'sha256' => hash('sha256', (string) $code),
            'notes' => null,
            'mandatory' => false,
            'is_published' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
