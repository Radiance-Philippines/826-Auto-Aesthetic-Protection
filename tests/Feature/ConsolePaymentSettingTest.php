<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffApi;
use Tests\TestCase;

/**
 * The React admin's Settings → Payments: the settings area, closed while
 * viewing as someone else, and the Filament page's rules and uploads.
 */
class ConsolePaymentSettingTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const PAYMENTS = '/console/api/owner/payment-settings';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        PaymentSetting::instance()->update(['reservation_fee_enabled' => false, 'amount_value' => 300, 'hold_minutes' => 45, 'instructions' => 'Kept']);
    }

    private function owner(): static
    {
        return $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return [
            'reservationFeeEnabled' => true, 'amountValue' => 500, 'holdMinutes' => 60, 'instructions' => 'Put your reference',
            'gcashEnabled' => true, 'gcashName' => '826', 'gcashNumber' => '0917 000 0000',
            'mayaEnabled' => false, 'mayaName' => '', 'mayaNumber' => '',
            ...$changes,
        ];
    }

    private function send(array $data): TestResponse
    {
        return $this->post(self::PAYMENTS, $data, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return ['read' => ['getJson'], 'save' => ['postJson']];
    }

    private function assertUntouched(): void
    {
        $this->assertSame([false, 300], [PaymentSetting::instance()->reservation_fee_enabled, PaymentSetting::instance()->amount_value]);
    }

    #[DataProvider('endpoints')]
    public function test_signed_out_is_401(string $method): void
    {
        $this->{$method}(self::PAYMENTS, $this->form())->assertUnauthorized();
        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_a_role_without_the_owner_panel_is_403(string $method): void
    {
        $this->actingAs($this->staffUser('branch_manager', Branch::factory()->create(), ['permissions' => ['settings']]));

        $this->{$method}(self::PAYMENTS, $this->form())->assertForbidden();
        $this->assertUntouched();
    }

    /**
     * The page is only in /owner and nobody is viewed as a super admin, so a
     * view as someone else never reaches it (ConsoleScreen also closes it).
     */
    #[DataProvider('endpoints')]
    public function test_it_is_closed_while_viewing_as_someone_else(string $method): void
    {
        $admin = User::factory()->owner()->create();
        $manager = $this->staffUser('branch_manager', Branch::factory()->create(), ['permissions' => ['settings']]);
        $this->actingAs($manager)->withSession([Impersonation::IMPERSONATOR => $admin->id, Impersonation::TARGET => $manager->id]);

        $this->{$method}(self::PAYMENTS, $this->form())->assertForbidden();
        $this->assertUntouched();
    }

    public function test_the_fee_and_the_accounts_are_saved_with_the_qr_as_uploaded(): void
    {
        $this->owner();

        $this->getJson(self::PAYMENTS)->assertOk()->assertJsonPath('values.amountValue', 300)->assertJsonPath('gcashQrUrl', null);

        $this->send([...$this->form(['reservationFeeEnabled' => '1', 'gcashEnabled' => '1', 'mayaEnabled' => '0']), 'gcashQr' => UploadedFile::fake()->image('qr.png', 200, 200)])
            ->assertOk()
            ->assertJsonPath('values.holdMinutes', 60);

        $settings = PaymentSetting::instance();
        $this->assertSame([true, 500, 60, 'fixed', 'Put your reference', '826'], [$settings->feeEnabled(), $settings->amount_value, $settings->hold_minutes, $settings->amount_rule, $settings->instructions, $settings->gcash_name]);
        $this->assertStringStartsWith('payments/', $settings->gcash_qr);
        $this->assertStringEndsWith('.png', $settings->gcash_qr);
        Storage::disk('public')->assertExists($settings->gcash_qr);

        $this->postJson(self::PAYMENTS, $this->form(['removegcashQr' => true]))->assertOk()->assertJsonPath('gcashQrUrl', null);
        Storage::disk('public')->assertMissing($settings->gcash_qr);
    }

    public function test_with_the_fee_off_its_fields_are_kept_as_they_were(): void
    {
        $this->owner();

        $this->postJson(self::PAYMENTS, $this->form(['reservationFeeEnabled' => false, 'amountValue' => '', 'holdMinutes' => 1, 'instructions' => '']))->assertOk();

        $settings = PaymentSetting::instance();
        $this->assertSame([false, 300, 45, 'Kept'], [$settings->reservation_fee_enabled, $settings->amount_value, $settings->hold_minutes, $settings->instructions]);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->owner();

        $this->postJson(self::PAYMENTS, [])->assertUnprocessable()->assertJsonValidationErrors(['reservationFeeEnabled', 'gcashEnabled', 'mayaEnabled']);
        $this->postJson(self::PAYMENTS, $this->form(['amountValue' => 0, 'holdMinutes' => 5, 'instructions' => str_repeat('a', 1001), 'gcashNumber' => 'text me', 'mayaName' => str_repeat('b', 121)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amountValue', 'holdMinutes', 'instructions', 'gcashNumber', 'mayaName']);
        $this->send([...$this->form(), 'mayaQr' => UploadedFile::fake()->create('qr.png', UploadRules::QR_MAX_KB + 1, 'image/png')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mayaQr' => 'Keep the picture under 5 MB.']);
        $this->send([...$this->form(), 'mayaQr' => UploadedFile::fake()->create('qr.pdf', 1, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('mayaQr');
        $this->assertUntouched();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
