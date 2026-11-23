<?php

namespace App\Http\Middleware;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Impersonation;
use App\Support\Panels;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every panel request (and every Livewire call): while a super admin
 * is viewing as someone, the stored super admin must still exist and still be
 * one, and the signed-in account must still be the target and still have a
 * panel. Otherwise the view ends. Also turns a visit to a blocked page into a notice.
 */
class ValidateImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Impersonation::active()) {
            return $next($request);
        }

        $livewire = $request->hasHeader('X-Livewire');
        $target = Auth::guard()->user();

        if (! Impersonation::impersonator()) {
            Impersonation::stop();

            return $livewire ? abort(419) : redirect(Filament::getPanel(Panels::OWNER)->getLoginUrl());
        }

        if (! $target instanceof User || (int) $target->getKey() !== (int) $request->session()->get(Impersonation::TARGET) || $target->isOwner() || ! $target->canAccessAnyPanel() || $target->isPendingInvite()) {
            Impersonation::stop();

            if ($livewire) {
                abort(419);
            }

            Notification::make()->warning()->title('That person can no longer be viewed')->body('Their account was removed or lost its access, so you are back as yourself.')->send();

            return redirect(UserResource::getUrl('index', panel: Panels::OWNER));
        }

        if ($request->isMethod('GET') && ! $livewire && $this->blocked($request)) {
            Notification::make()->warning()->title('Not available while viewing as someone else')->body('Stop viewing as this person first.')->send();

            return redirect(Panels::homeUrl($target));
        }

        return $next($request);
    }

    private function blocked(Request $request): bool
    {
        $class = $request->route() ? explode('@', $request->route()->getActionName())[0] : null;

        return $class !== null && class_exists($class) && in_array(BlockedWhileImpersonating::class, class_uses_recursive($class), true);
    }
}
