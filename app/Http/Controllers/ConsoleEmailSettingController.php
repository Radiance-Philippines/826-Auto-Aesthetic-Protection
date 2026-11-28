<?php

namespace App\Http\Controllers;

use App\Models\MailSetting;
use App\Support\MailCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The React admin's Email settings, like the Filament page (settings area,
 * closed while viewing as someone). The mailbox password is write-only: it is
 * never sent back, and a blank box keeps it.
 */
class ConsoleEmailSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->record(MailSetting::instance());
    }

    public function update(Request $request): JsonResponse
    {
        return $this->record($this->store($request));
    }

    /**
     * Saves, then sends an email to the mailbox itself.
     */
    public function test(Request $request): JsonResponse
    {
        $settings = $this->store($request);

        try {
            MailCheck::send($settings);
        } catch (Throwable $e) {
            return $this->record($settings, ['ok' => false, 'title' => 'Sending failed', 'body' => "Check the address and password. ({$e->getMessage()})"]);
        }

        return $this->record($settings, ['ok' => true, 'title' => 'Email works', 'body' => "Sent a test email to {$settings->address}. It should arrive in a minute."]);
    }

    private function store(Request $request): MailSetting
    {
        $settings = MailSetting::instance();
        $data = $request->validate([
            'address' => ['required', 'email', 'max:190'],
            'fromName' => ['nullable', 'string', 'max:80'],
            'password' => [Rule::requiredIf(! $settings->isConfigured()), 'nullable', 'string', 'max:190'],
            'notifyEmail' => ['nullable', 'email', 'max:190'],
            'smtpHost' => ['required', 'string', 'max:190'],
            'smtpPort' => ['required', 'integer', Rule::in([465, 587])],
        ], [], ['address' => 'email address', 'fromName' => 'sender name', 'password' => 'mailbox password', 'notifyEmail' => 'alert address', 'smtpHost' => 'SMTP server', 'smtpPort' => 'SMTP port']);

        $settings->update([
            'address' => $data['address'],
            'from_name' => $data['fromName'] ?? null,
            'notify_email' => $data['notifyEmail'] ?? null,
            'smtp_host' => $data['smtpHost'],
            'smtp_port' => (int) $data['smtpPort'],
            ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : []),
        ]);

        return $settings;
    }

    /**
     * @param  array{ok: bool, title: string, body: string}|null  $test
     */
    private function record(MailSetting $settings, ?array $test = null): JsonResponse
    {
        $settings->refresh();

        return response()->json([
            'address' => $settings->address,
            'fromName' => $settings->from_name,
            'notifyEmail' => $settings->notify_email,
            'smtpHost' => $settings->smtp_host,
            'smtpPort' => $settings->smtp_port,
            'configured' => $settings->isConfigured(),
            'test' => $test,
        ])->header('Cache-Control', 'no-store');
    }
}
