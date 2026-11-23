<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettings;
use App\Filament\Pages\ReferralSettings;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\MailSetting;
use App\Models\ReferralSetting;
use App\Models\User;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsShop;
use Tests\TestCase;

/**
 * A choice with exactly two fixed options is radio buttons, never a dropdown.
 */
class TwoOptionsTest extends TestCase
{
    use BuildsShop, RefreshDatabase;

    /**
     * Two-option dropdowns that are allowed to stay, as "path::field" with the reason.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->actingAs(User::factory()->owner()->create());
    }

    public function test_no_filament_select_has_exactly_two_fixed_options(): void
    {
        $found = [];

        foreach ($this->filamentFiles() as $path) {
            $source = file_get_contents($path);
            $name = str_replace(base_path().'/', '', $path);

            preg_match_all('/(?:Select|SelectFilter)::make\(\'([^\']+)\'\)(.*?)(?=(?:\w+)::make\(|\z)/s', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $field, $chain]) {
                // Several picks are not a radio choice
                if (str_contains($chain, '->multiple()') || ! preg_match('/->options\(/', $chain, $call, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                if ($this->fixedOptionCount(substr($chain, $call[0][1] + 10), $source) === 2 && ! isset(self::ALLOWED["{$name}::{$field}"])) {
                    $found[] = "{$name}::{$field}";
                }
            }
        }

        $this->assertSame([], $found, 'Two fixed options go in ToggleButtons or Radio, not a Select: '.implode(', ', $found));
    }

    public function test_the_site_has_one_dropdown_and_it_is_the_year(): void
    {
        $uses = [];

        foreach (glob(resource_path('js/{components,pages}/*.tsx'), GLOB_BRACE) as $path) {
            $source = file_get_contents($path);
            $name = basename($path);

            // Our own SelectField (Form.tsx defines it) and any native select
            $this->assertStringNotContainsString('<select', $source, "{$name} has a native select");

            if ($name !== 'Form.tsx' && preg_match_all('/<SelectField\b[^\n]*options=\{(\w+)\}/', $source, $matches)) {
                $uses[$name] = $matches[1];
            }
        }

        $this->assertSame(['UnitFields.tsx' => ['YEARS']], $uses, 'The site keeps a dropdown for the long Year list only: two options are radio pills (Chips) or radio cards (ChoiceCard).');
    }

    public function test_the_scan_sees_a_two_option_select(): void
    {
        $this->assertSame(2, $this->fixedOptionCount("[1 => 'Yes', 0 => 'No'])", ''));
        $this->assertSame(2, $this->fixedOptionCount("['a' => 'A', 'b' => fn () => 'B, or C'])->required()", ''));
        $this->assertSame(3, $this->fixedOptionCount("['a', 'b', 'c'])", ''));
        $this->assertSame(2, $this->fixedOptionCount('ReferralSetting::TYPES)', 'use App\Models\ReferralSetting;'));
        $this->assertNull($this->fixedOptionCount('fn () => Branch::query()->pluck()->all())', ''));
    }

    public function test_the_referral_discount_type_is_two_radio_buttons_with_the_same_values(): void
    {
        Livewire::test(ReferralSettings::class)
            ->assertFormFieldExists('discount_type', fn (ToggleButtons $field) => array_keys($field->getOptions()) === ['fixed', 'percent'] && $field->isInline())
            ->fillForm(['enabled' => true, 'discount_type' => 'percent', 'discount_value' => 15, 'owner_points' => 50])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('percent', ReferralSetting::current()->discount_type);

        Livewire::test(ReferralSettings::class)
            ->assertSet('data.discount_type', 'percent')
            ->fillForm(['discount_type' => 'fixed', 'discount_value' => 500])
            ->call('save');

        $this->assertSame(['fixed', 500], [ReferralSetting::current()->discount_type, ReferralSetting::current()->discount_value]);
    }

    public function test_the_smtp_port_is_two_radio_buttons_and_saves_a_number(): void
    {
        MailSetting::instance()->update(['address' => 'hello@826auto.test', 'password' => 'secret', 'smtp_port' => 465]);

        Livewire::test(EmailSettings::class)
            ->assertFormFieldExists('smtp_port', fn (ToggleButtons $field) => array_keys($field->getOptions()) === [465, 587])
            ->fillForm(['smtp_port' => 587])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(587, MailSetting::instance()->smtp_port);
    }

    public function test_the_home_site_questions_are_yes_no_radio_buttons_that_save_booleans(): void
    {
        $booking = Booking::factory()->home()->create(['branch_id' => $this->branch->id, 'has_water' => null, 'has_power' => null, 'has_shade' => null]);

        Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertFormFieldExists('has_water', fn (ToggleButtons $field) => $field->getOptions() === [1 => 'Yes', 0 => 'No'])
            ->fillForm(['has_water' => '1', 'has_power' => '0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $booking->refresh();
        $this->assertSame([true, false, null], [$booking->has_water, $booking->has_power, $booking->has_shade]);
    }

    public function test_the_kind_filter_is_two_radio_buttons_and_still_filters(): void
    {
        $onsite = Booking::factory()->create(['branch_id' => $this->branch->id]);
        $home = Booking::factory()->home()->create(['branch_id' => $this->branch->id]);

        Livewire::test(ListBookings::class)
            ->assertCanSeeTableRecords([$onsite, $home])
            ->filterTable('kind', ['kind' => 'home'])
            ->assertCanSeeTableRecords([$home])
            ->assertCanNotSeeTableRecords([$onsite])
            ->filterTable('kind', ['kind' => 'onsite'])
            ->assertCanSeeTableRecords([$onsite])
            ->assertCanNotSeeTableRecords([$home])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$onsite, $home]);
    }

    /**
     * @return list<string>
     */
    private function filamentFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament'), \FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * How many options a literal array (or a Class::CONST array) holds, or null when they come from data.
     */
    private function fixedOptionCount(string $argument, string $source): ?int
    {
        $argument = ltrim($argument);

        if (preg_match('/^(\w+)::([A-Z_]+)\s*[,)]/', $argument, $constant)) {
            if (! preg_match('/^use ([\w\\\\]*\\\\'.$constant[1].');/m', $source, $import)) {
                return null;
            }

            $value = constant($import[1].'::'.$constant[2]);

            return is_array($value) ? count($value) : null;
        }

        if (! str_starts_with($argument, '[')) {
            return null;
        }

        $depth = 0;
        $count = 0;
        $filled = false;
        $quote = null;

        for ($i = 0, $length = strlen($argument); $i < $length; $i++) {
            $char = $argument[$i];

            if ($quote !== null) {
                $quote = $char === $quote && $argument[$i - 1] !== '\\' ? null : $quote;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $filled = $filled || $depth === 1;
            } elseif ($char === '[' || $char === '(') {
                $depth++;
                $filled = $filled || $depth > 1;
            } elseif ($char === ']' || $char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $count + ($filled ? 1 : 0);
                }
            } elseif ($char === ',' && $depth === 1) {
                $count += $filled ? 1 : 0;
                $filled = false;
            } elseif ($depth === 1 && trim($char) !== '') {
                $filled = true;
            }
        }

        return null;
    }
}
