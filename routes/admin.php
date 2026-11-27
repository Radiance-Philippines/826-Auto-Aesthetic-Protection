<?php

use App\Http\Controllers\ConsoleMeController;
use App\Http\Controllers\ConsoleShellController;
use App\Http\Middleware\AuthenticateConsole;
use App\Http\Middleware\OpenConsolePanel;
use App\Http\Middleware\ValidateImpersonation;
use App\Providers\Filament\PanelPages;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
| The React admin, beside the Filament panels until the cutover. Required by
| routes/web.php, so every route has the web group: session, cookies and CSRF
| on every write.
|
|   /console/{panel}/...       the React shell (resources/js/admin)
|   /console/api/{panel}/...   its JSON: `me` here, then one file per screen in routes/admin/
|
| A screen's JSON (routes/admin/<slug>.php):
| - Access: group the routes under `ConsoleScreen::class.':<slug>'` (the Filament slug). That checks the
|   panel shows the screen and the page's own canAccess(), and closes settings pages while impersonating.
|   Each action still authorizes what it does (policy or area), like the Filament action it replaces.
| - Branch scope: find branch records with `visibleTo($user)` (it follows the top bar's selector) and
|   answer 404 for another branch's. Never route-model-bind a branch record.
| - The {panel} parameter is consumed by the group: controllers don't receive it.
| - Reuse app/Support and the staff app's controllers (they work with the session); don't copy their rules.
| - Errors are Laravel's: 401 signed out, 403 {message} (plus `redirect` while two-factor setup is due),
|   404, 419 stale page, 422 {message, errors: {field: [...]}}, 429.
| - Field names are camelCase. A short list answers {data: [...]}. A long one takes
|   ?search=&sort=&desc=1&page= and answers {data: [...], meta: {total, perPage}}.
| - Cached routes: never put two closures on one line.
*/
Route::prefix('console')->name('console.')->group(function () {
    $signedIn = [ValidateImpersonation::class, AuthenticateConsole::class, AuthenticateSession::class];

    Route::get('/', [ConsoleShellController::class, 'home'])->middleware($signedIn)->name('home');

    Route::prefix('api/{panel}')
        ->name('api.')
        ->whereIn('panel', PanelPages::panels())
        ->middleware([...$signedIn, OpenConsolePanel::class, 'throttle:console'])
        ->group(function () {
            Route::get('me', ConsoleMeController::class)->name('me');

            foreach (glob(__DIR__.'/admin/*.php') as $screen) {
                require $screen;
            }
        });

    Route::get('{panel}/{path?}', [ConsoleShellController::class, 'show'])
        ->whereIn('panel', PanelPages::panels())
        ->where('path', '.*')
        ->middleware([...$signedIn, OpenConsolePanel::class])
        ->name('page');
});
