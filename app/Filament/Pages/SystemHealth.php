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
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'version' => AppRelease::latestPublished()?->version,
            'checks' => [
                ['label' => 'Email is set up', 'on' => MailSetting::instance()->isConfigured()],
                ['label' => 'Meta (Messenger) is connected', 'on' => MetaSetting::instance()->isReady()],
                ['label' => 'Google sign-in is set up', 'on' => GoogleSignIn::enabled()],
                ['label' => 'Live updates (Radiance Flow) are on', 'on' => FlowLive::enabled()],
            ],
        ];
    }
}
