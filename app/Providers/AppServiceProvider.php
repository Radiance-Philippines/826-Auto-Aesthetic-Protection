<?php

namespace App\Providers;

use App\Filament\Support\PanelDefaults;
use App\Http\Middleware\ValidateImpersonation;
use App\Models\MailSetting;
use App\Support\Live\LiveEvents;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sign-in lands in the person's own panel (owner, sales or operations)
        $this->app->bind(LoginResponse::class, \App\Http\Responses\LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            // Every generated URL (emails, password resets, assets) uses APP_URL,
            // whatever Host header the request came with
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        // Mail goes out through the mailbox set in Email settings, once it has a password
        $this->app->afterResolving('mail.manager', fn () => MailSetting::applyToConfig());

        // Choosing a password from an invite (or reset) link proves the email is theirs
        Event::listen(PasswordReset::class, function (PasswordReset $event) {
            $event->user->forceFill(['email_verified_at' => $event->user->email_verified_at ?? now()])->save();
        });

        // Re-checked on every Livewire call too, not only on page loads
        Livewire::addPersistentMiddleware([ValidateImpersonation::class]);

        // Filament's own dropdowns and date pickers, and an icon on every button
        PanelDefaults::apply();

        // The passkey relying-party id is the host the admin is opened on, unless PASSKEY_RP_ID says otherwise
        if (blank(config('passkeys.relying_party.id'))) {
            config(['passkeys.relying_party.id' => $this->app->runningInConsole() ? parse_url((string) config('app.url'), PHP_URL_HOST) : request()->getHost()]);
        }

        // Live updates over Radiance Flow (nothing happens until the app token is set)
        LiveEvents::listen();

        $this->limitRequests();
    }

    /**
     * Each kind of request has its own budget per visitor. Plain
     * "throttle:5,1" shares one counter between every route, so browsing
     * the calendar could use up the budget for sending a request.
     */
    private function limitRequests(): void
    {
        $by = fn (string $name) => fn (Request $request) => $request->user('guest')?->getAuthIdentifier()
            ? "{$name}|guest:".$request->user('guest')->getAuthIdentifier()
            : "{$name}|".$request->ip();

        RateLimiter::for('availability', fn (Request $request) => Limit::perMinute(120)->by($by('availability')($request)));
        RateLimiter::for('bookings', fn (Request $request) => Limit::perMinute(5)->by($by('bookings')($request)));
        RateLimiter::for('my-bookings', fn (Request $request) => Limit::perMinute(60)->by($by('my-bookings')($request)));
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(6)->by($by('payments')($request)));
        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute(5)->by($by('reviews')($request)));
        // Like the password form (5 a minute), counting every try; the one-time challenge fetch is cheaper
        RateLimiter::for('passkey-login', fn (Request $request) => Limit::perMinute(5)->by('passkey-login|'.$request->ip()));
        RateLimiter::for('passkey-options', fn (Request $request) => Limit::perMinute(20)->by('passkey-options|'.$request->ip()));
        // Each try counts, like the other sign-ins
        RateLimiter::for('app-sign-in', fn (Request $request) => Limit::perMinute(10)->by('app-sign-in|'.$request->ip()));
        // A page asks again about every 4 minutes, plus reconnects; 20 a minute leaves room and stops a loop
        RateLimiter::for('live-ticket', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->getAuthIdentifier() ? 'live-ticket|user:'.$request->user()->getAuthIdentifier() : $by('live-ticket')($request)));
        // The team app: sign-in by address (each try counts; the per-email count is in the controller) and a generous per-person budget after it
        RateLimiter::for('staff-sign-in', fn (Request $request) => [
            Limit::perMinute(10)->by('staff-sign-in|'.$request->ip()),
            Limit::perDay(100)->by('staff-sign-in|day|'.$request->ip()),
        ]);
        RateLimiter::for('staff', fn (Request $request) => Limit::perMinute(240)->by('staff|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Customers' own accounts: 5 a minute per visitor and per email address, each try counting
        foreach (['customer-register', 'customer-login', 'customer-forgot'] as $name) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute(5)->by("{$name}|ip|".$request->ip()),
                Limit::perMinute(5)->by("{$name}|email|".sha1(Str::lower(trim((string) $request->input('email'))))),
            ]);
        }
        // The React admin's own sign-in pages: a minute's budget per visitor and per email address, and a
        // day's budget per visitor (per visitor and address together, so nobody can lock another person out)
        foreach (['console-sign-in' => [5, 30, 100], 'console-forgot' => [2, 5, 20], 'console-reset' => [2, 10, 20]] as $name => [$minute, $dayForAddress, $dayForVisitor]) {
            RateLimiter::for($name, function (Request $request) use ($name, $minute, $dayForAddress, $dayForVisitor): array {
                $email = sha1(Str::lower(trim(is_string($request->input('email')) ? $request->input('email') : '')));

                return [
                    Limit::perMinute($minute)->by("{$name}|ip|".$request->ip()),
                    Limit::perMinute($minute)->by("{$name}|email|{$email}"),
                    Limit::perDay($dayForAddress)->by("{$name}|day|{$request->ip()}|{$email}"),
                    Limit::perDay($dayForVisitor)->by("{$name}|day|ip|".$request->ip()),
                ];
            });
        }
        // The per-account day limit on wrong codes is TwoFactor's
        RateLimiter::for('console-two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('console-two-factor|'.$request->ip()),
            Limit::perDay(60)->by('console-two-factor|day|'.$request->ip()),
        ]);
        // The profile's actions that check a password or a code: one budget of 5 a minute per person and per address
        RateLimiter::for('console-profile', fn (Request $request) => [
            Limit::perMinute(5)->by('console-profile|user|'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(5)->by('console-profile|ip|'.$request->ip()),
            Limit::perDay(50)->by('console-profile|day|'.$request->user()?->getAuthIdentifier()),
        ]);
        // The React admin's JSON: a generous per-person budget
        RateLimiter::for('console', fn (Request $request) => Limit::perMinute(240)->by('console|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('google', fn (Request $request) => Limit::perMinute(20)->by('google|'.$request->ip()));
    }
}
