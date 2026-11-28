<?php

namespace App\Support;

use App\Models\MailSetting;
use App\Models\MetaSetting;
use App\Support\Live\FlowLive;

/**
 * What is switched on, as yes or no: for System health (both admins) and the
 * team app's System tab. Never a key, token or password.
 */
final class SystemChecks
{
    /**
     * @return array{email: bool, meta: bool, google: bool, flow: bool}
     */
    public static function health(): array
    {
        return [
            'email' => MailSetting::instance()->isConfigured(),
            'meta' => MetaSetting::instance()->isReady(),
            'google' => GoogleSignIn::enabled(),
            'flow' => FlowLive::enabled(),
        ];
    }

    /**
     * The same, with the words the health screen shows.
     *
     * @return list<array{label: string, on: bool}>
     */
    public static function labelled(): array
    {
        $health = self::health();

        return [
            ['label' => 'Email is set up', 'on' => $health['email']],
            ['label' => 'Meta (Messenger) is connected', 'on' => $health['meta']],
            ['label' => 'Google sign-in is set up', 'on' => $health['google']],
            ['label' => 'Live updates (Radiance Flow) are on', 'on' => $health['flow']],
        ];
    }
}
