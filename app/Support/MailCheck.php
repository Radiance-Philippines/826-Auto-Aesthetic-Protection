<?php

namespace App\Support;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;

/**
 * "Save and send a test": an email to the mailbox itself, through the saved
 * settings. Throws when sending fails.
 */
final class MailCheck
{
    public static function send(MailSetting $settings): void
    {
        MailSetting::applyToConfig();
        Mail::purge();
        Mail::raw('This is a test from the '.config('app.name').' admin. If you can read it, sending works.', fn ($message) => $message
            ->to($settings->address)
            ->subject(config('app.name').' admin: test email'));
    }
}
