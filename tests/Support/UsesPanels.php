<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Panels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The admin is four panels (/owner, /sales, /operations, /system), each with
 * its own pages. A test says which page it wants and the helper puts it in the
 * first panel of whoever is signed in that has it (a super admin's Bookings are
 * in /sales, the Queue in /operations, Users in /owner), else in their home
 * panel, so each role is tested where the page lives.
 */
trait UsesPanels
{
    /**
     * @param  string  $path  e.g. "/bookings/3", or "" for the panel's home
     */
    protected function panel(string $path = '', ?User $as = null): string
    {
        $user = $as ?? auth()->user();

        if (! $user instanceof User) {
            return '/'.Panels::OWNER.$path;
        }

        foreach (Panels::idsFor($user) as $id) {
            if ($path === '' || $this->panelShows($id, $path)) {
                return '/'.$id.$path;
            }
        }

        return '/'.Panels::homeId($user).$path;
    }

    private function panelShows(string $panel, string $path): bool
    {
        try {
            $route = Route::getRoutes()->match(Request::create("/{$panel}".strtok($path, '?')));
        } catch (HttpExceptionInterface) {
            return false;
        }

        $class = Panels::placedClass($route);

        return ! $route->isFallback && $class !== null && Panels::shows($class, $panel);
    }

    /**
     * The dashboard: the home of /owner and /sales, but /operations opens on the queue.
     */
    protected function dashboard(?User $as = null): string
    {
        $user = $as ?? auth()->user();

        return $this->panel($user instanceof User && Panels::homeId($user) === Panels::OPERATIONS ? '/dashboard' : '', $user);
    }

    /**
     * The same page in one named panel, whoever is signed in.
     */
    protected function inPanel(string $panel, string $path = ''): string
    {
        return '/'.$panel.$path;
    }
}
