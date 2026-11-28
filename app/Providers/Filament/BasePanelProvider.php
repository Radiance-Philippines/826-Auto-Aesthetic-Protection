<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\AuthenticatePanel;
use App\Http\Middleware\RequireTwoFactorWhenOn;
use App\Http\Middleware\ValidateImpersonation;
use App\Support\Panels;
use App\Support\TwoFactor;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What the admin's panels share: sign-in (password, passkey, two-factor),
 * the profile, the bell and live updates, the brand and the one theme. A
 * panel only differs in its id, its name and which resources, pages and
 * dashboard widgets it registers (see PanelPages).
 */
abstract class BasePanelProvider extends PanelProvider
{
    /**
     * A neutral graphite until 826's own colours are set in the design
     * pass: filled buttons at 600 under white text, links at 700.
     *
     * @var array<int, string>
     */
    public const BRAND = [
        50 => '#f6f6f7',
        100 => '#e9eaec',
        200 => '#d3d5d9',
        300 => '#b0b4bb',
        400 => '#878c96',
        500 => '#6a707b',
        600 => '#4c515a',
        700 => '#3d4148',
        800 => '#2e3136',
        900 => '#1f2124',
        950 => '#121315',
    ];

    /**
     * The panel's id; its path is the same.
     */
    abstract protected function panelId(): string;

    abstract protected function brandName(): string;

    protected function configure(Panel $panel): Panel
    {
        return $panel;
    }

    /**
     * Name, email, password, two-factor and passkeys, from the user menu.
     */
    protected function hasProfile(): bool
    {
        return true;
    }

    public function panel(Panel $panel): Panel
    {
        $panel = $this->configure($panel
            ->id($this->panelId())
            ->path($this->panelId())
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->login(Login::class)
            ->passwordReset()
            // Two-factor sign-in: an authenticator app (with recovery codes) or an emailed
            // code, set up from the profile. Whether it is compulsory is Settings → Security,
            // checked on each request by RequireTwoFactorWhenOn (so always "required" here).
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable()->codeWindow(TwoFactor::WINDOW),
                EmailAuthentication::make(),
            ], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireTwoFactorWhenOn::class)
            // The bell: heads-ups from operations to sales
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Live updates from Radiance Flow: the bell, queue, bookings and inbox look again when something changes
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.live'))
            // The branch the person is working in (#695)
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn () => view('filament.branch-selector'))
            // The panel launcher (a grid button) for people who can open more than one panel
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn () => view('filament.panel-launcher'))
            // A super admin viewing as someone else: the sticky banner with "Stop"
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('filament.impersonation-banner'))
            ->brandName($this->brandName())
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => self::BRAND,
                'gray' => Color::Stone,
            ])
            // Fonts are self-hosted in the theme (@fontsource), not loaded from a CDN
            ->font('Archivo Variable', provider: LocalFontProvider::class)
            // Pages use the whole width of the window: the default 7xl column left wide empty margins
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Bookings'),
                NavigationGroup::make('Shop'),
                NavigationGroup::make('Site'),
                NavigationGroup::make('Settings'),
                NavigationGroup::make('System'),
            ])
            ->userMenuItems([
                Action::make('viewSite')
                    ->label('View site')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn () => url('/'), shouldOpenInNewTab: true),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ValidateImpersonation::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                AuthenticatePanel::class,
            ]));

        return $this->hasProfile() ? $panel->profile(EditProfile::class, isSimple: false) : $panel;
    }
}
