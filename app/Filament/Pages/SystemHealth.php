<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ShownInItsPanels;
use App\Models\AppRelease;
use App\Models\MailSetting;
use App\Models\MetaSetting;
use App\Support\GoogleSignIn;
use App\Support\Live\FlowLive;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The home of /system: what is switched on, as yes or no and the live app
 * version. Never a key, token or password.
 */
class SystemHealth extends Page
{
    use ShownInItsPanels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System health';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'System health';

    protected static ?string $slug = 'health';

    protected string $view = 'filament.pages.system-health';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAccessArea('settings');
    }

    /**
     * The panel's home, like a dashboard.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    /**
     * What is switched on: shared with the team app's System tab.
     *
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
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $health = self::health();

        return [
            'version' => AppRelease::latestPublished()?->version,
            'checks' => [
                ['label' => 'Email is set up', 'on' => $health['email']],
                ['label' => 'Meta (Messenger) is connected', 'on' => $health['meta']],
                ['label' => 'Google sign-in is set up', 'on' => $health['google']],
                ['label' => 'Live updates (Radiance Flow) are on', 'on' => $health['flow']],
            ],
        ];
    }
}
