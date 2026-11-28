<?php

namespace App\Http\Controllers;

use App\Support\AdminConsole;
use App\Support\Impersonation;
use App\Support\Panels;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The one HTML page of the React admin; its screens are drawn in the browser
 * (resources/js/admin).
 */
class ConsoleShellController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        $panel = Panels::homeId($request->user());

        abort_unless($panel !== null, 403);

        return redirect()->route('console.page', ['panel' => $panel]);
    }

    /**
     * A screen in a panel that does not have it moves to this person's panel
     * that does, as the Filament panels do.
     */
    public function show(Request $request, ?string $path = null): Response|RedirectResponse
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $slug = explode('/', (string) $path)[0];
        $status = 200;

        if ($slug === 'profile') {
            // Everyone's own, so no Filament screen to ask; closed while viewing as someone else
            abort_if(Impersonation::active(), 403, 'Not available while viewing as someone else.');
        } elseif ($slug !== '') {
            $class = AdminConsole::screens($panel)[$slug] ?? null;

            if ($class !== null) {
                AdminConsole::authorize($class);
            } elseif (AdminConsole::exists($slug)) {
                foreach (Panels::idsFor($request->user()) as $id) {
                    if (isset(AdminConsole::screens(Filament::getPanel($id))[$slug])) {
                        return redirect()->route('console.page', ['panel' => $id, 'path' => $path] + $request->query());
                    }
                }

                abort(403);
            } else {
                $status = 404;
            }
        }

        return response()->view('console', [
            'title' => (string) $panel->getBrandName(),
            'console' => [
                'base' => AdminConsole::basePath(),
                'panel' => $panel->getId(),
                'logo' => is_file(public_path(SiteController::LOGO)) ? asset(SiteController::LOGO) : null,
            ],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
