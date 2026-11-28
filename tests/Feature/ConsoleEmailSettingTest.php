<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MailSetting;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\StaffApi;
use Tests\TestCase;

class ConsoleEmailSettingTest extends TestCase
{
    use RefreshDatabase, StaffApi;

    private const MAIL = '/console/api/system/email-settings';

    protected function setUp(): void
    {
        parent::setUp();

        // The saved mailbox must never be dialled from a test
        config(['mail.use_mailbox_settings' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(array $with = []): array
    {
        return ['address' => 'hello@826auto.test', 'fromName' => '826', 'password' => 'mailbox-secret', 'notifyEmail' => '', 'smtpHost' => 'smtp.hostinger.com', 'smtpPort' => 465, ...$with];
    }

    public function test_signed_out_is_401_other_roles_403_and_closed_while_viewing(): void
    {
        $this->withoutVite();
        $this->getJson(self::MAIL)->assertUnauthorized();
        $this->postJson(self::MAIL, $this->fields())->assertUnauthorized();
        $this->postJson(self::MAIL.'/test', $this->fields())->assertUnauthorized();

        $this->actingAs($this->staffUser('sales_admin', Branch::factory()->create(), ['permissions' => ['settings']]));
        $this->getJson(self::MAIL)->assertForbidden();
        $this->postJson(self::MAIL.'/test', $this->fields())->assertForbidden();

        $admin = User::factory()->owner()->create();
        $this->actingAs($admin)->withSession([Impersonation::IMPERSONATOR => User::factory()->owner()->create()->id, Impersonation::TARGET => $admin->id]);
        $this->assertContains($this->postJson(self::MAIL, $this->fields())->getStatusCode(), [403, 419]);
        $this->assertNull(MailSetting::instance()->address);
    }

    public function test_the_password_is_saved_kept_when_blank_and_never_sent_back(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::MAIL, $this->fields(['password' => '']))->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $saved = $this->postJson(self::MAIL, $this->fields())->assertOk()->assertJsonPath('configured', true)->assertJsonMissingPath('password');
        $kept = $this->postJson(self::MAIL, $this->fields(['password' => '', 'smtpPort' => 587]))->assertOk()->assertJsonPath('smtpPort', 587);
        $read = $this->getJson(self::MAIL)->assertOk();

        $this->assertSame('mailbox-secret', MailSetting::instance()->password);

        foreach ([$saved, $kept, $read] as $response) {
            $this->assertStringNotContainsString('mailbox-secret', $response->getContent());
        }
    }

    public function test_save_and_send_a_test(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::MAIL.'/test', $this->fields())
            ->assertOk()
            ->assertJsonPath('test.ok', true)
            ->assertJsonPath('test.title', 'Email works');

        Mail::shouldReceive('purge');
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('Connection refused'));
        $this->postJson(self::MAIL.'/test', $this->fields(['address' => 'other@826auto.test']))
            ->assertOk()
            ->assertJsonPath('test', ['ok' => false, 'title' => 'Sending failed', 'body' => 'Check the address and password. (Connection refused)']);
        // The settings were saved before the send
        $this->assertSame('other@826auto.test', MailSetting::instance()->address);
    }

    public function test_what_the_form_refuses(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->postJson(self::MAIL, [])->assertUnprocessable()->assertJsonValidationErrors(['address', 'password', 'smtpHost', 'smtpPort']);
        $this->postJson(self::MAIL, $this->fields(['address' => 'x', 'notifyEmail' => 'y', 'fromName' => str_repeat('a', 81), 'smtpPort' => 25]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address', 'notifyEmail', 'fromName', 'smtpPort']);
    }
}
