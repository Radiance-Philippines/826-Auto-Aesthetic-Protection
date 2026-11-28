<?php

namespace App\Http\Controllers;

use App\Support\AdminConsole;
use App\Support\AppLauncher;
use App\Support\CurrentBranch;
use App\Support\Impersonation;
use App\Support\Live\FlowLive;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /console/api/{panel}/me: everything the shell around a screen shows.
 */
class ConsoleMeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $panel = Filament::getCurrentOrDefaultPanel();
        $tiles = AppLauncher::tiles($user, $panel->getId());
        $branches = CurrentBranch::options($user);

        return response()->json([
            'user' => ['name' => $user->name, 'email' => $user->email, 'roleLabel' => $user->roleLabel()],
            'panel' => ['id' => $panel->getId(), 'brand' => (string) $panel->getBrandName()],
            'navigation' => AdminConsole::navigation($panel),
            'launcher' => AppLauncher::worthShowing($tiles) ? array_map(fn (array $tile) => [
                'key' => $tile['key'],
                'label' => $tile['label'],
                'glyph' => $tile['glyph'],
                'state' => $tile['state'],
                'href' => $tile['state'] === AppLauncher::SOON ? null : route('console.page', ['panel' => $tile['key']]),
            ], $tiles) : [],
            'branches' => [
                'options' => collect($branches)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all(),
                'chosen' => CurrentBranch::for($user),
                'locked' => ! $user->isOwner() && count($branches) === 1,
                // The Filament top bar's endpoint: a form post that comes back to the page
                'action' => route('panel.branch'),
            ],
            'impersonating' => Impersonation::active() ? [
                'name' => $user->name,
                'roleLabel' => $user->roleLabel(),
                'branches' => Impersonation::branchesLabel($user),
                'stopUrl' => route('impersonation.stop'),
            ] : null,
            'links' => [
                'profile' => $panel->getProfileUrl(),
                'classic' => url($panel->getId()),
                'site' => url('/'),
                'logout' => route('console.logout'),
            ],
            'live' => FlowLive::ticketsEnabled() ? route('live.ticket') : null,
        ])->header('Cache-Control', 'no-store');
    }
}
