<?php

namespace App\Http\Middleware;

use App\Support\AdminConsole;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ConsoleScreen:queue` on a console JSON route: the panel must show that
 * screen (404) and the person must be allowed to open it (403), by the
 * Filament page's or resource's own rules. After OpenConsolePanel.
 */
class ConsoleScreen
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $class = AdminConsole::screens(Filament::getCurrentOrDefaultPanel())[$slug] ?? null;

        abort_unless($class !== null, 404);
        AdminConsole::authorize($class);

        return $next($request);
    }
}
