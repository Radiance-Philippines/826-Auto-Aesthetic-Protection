<?php

use App\Http\Middleware\AuthenticateGuestToken;
use App\Http\Middleware\AuthenticateUserToken;
use App\Http\Middleware\ConsoleOnly;
use App\Http\Middleware\SkipForgeryCheckForBearer;
use App\Http\Middleware\StartSessionUnlessBearer;
use App\Http\Middleware\ValidateImpersonation;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production sits behind Hostinger's CDN: trust only the forwarded
        // client IP (for throttling) and scheme. Never the forwarded host, or
        // links in emails (password resets) could point at another domain.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Old admin addresses to the React admin, when ADMIN_CONSOLE_ONLY is on
        $middleware->append(ConsoleOnly::class);

        // Meta's webhook proves itself with a signature, not a CSRF token
        $middleware->preventRequestForgery(except: ['webhooks/meta']);

        // The customer app calls the site's /api with a bearer token: no session, no CSRF token
        $middleware->web(replace: [
            StartSession::class => StartSessionUnlessBearer::class,
            PreventRequestForgery::class => SkipForgeryCheckForBearer::class,
        ]);
        // After the session starts but before sign-in is checked: a deleted target must end the view, not send to the login page
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ValidateImpersonation::class);
        $middleware->alias(['guest.token' => AuthenticateGuestToken::class, 'user.token' => AuthenticateUserToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'console/api/*') || $request->expectsJson(),
        );
    })->create();
