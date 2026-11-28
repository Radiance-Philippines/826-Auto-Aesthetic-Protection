<?php

namespace App\Http\Middleware;

use App\Providers\Filament\PanelPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With ADMIN_CONSOLE_ONLY on, every old admin address opens the same place in
 * the React admin: bookmarks, app tiles and links already sent keep working.
 * Off, the Filament panels answer as before.
 */
class ConsoleOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $panels = implode('|', ['admin', ...PanelPages::panels()]);

        if (! config('app.console_only') || ! $request->isMethod('GET') || ! preg_match("#^({$panels})(?:/(.*))?$#", $request->path(), $found)) {
            return $next($request);
        }

        $panel = $found[1];
        $rest = $found[2] ?? '';

        return match (true) {
            str_starts_with($rest, 'password-reset/reset') => redirect()->route('console.password.reset', $request->only('token', 'email')),
            str_starts_with($rest, 'password-reset') => redirect()->route('console.password.forgot'),
            $rest === 'login' => redirect()->route('console.login'),
            // The shared address sends each person to their own panel first; that one is caught next
            $panel === 'admin' => $rest === '' ? redirect()->route('console.home') : $next($request),
            str_starts_with($rest, 'multi-factor') => redirect()->route('console.page', ['panel' => $panel, 'path' => 'profile']),
            default => redirect()->route('console.page', ['panel' => $panel, 'path' => $rest !== '' ? $rest : null] + $request->query()),
        };
    }
}
