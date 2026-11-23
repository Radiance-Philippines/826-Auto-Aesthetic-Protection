<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Panels;

/**
 * The admin is three panels (/owner, /sales, /operations). A test says which
 * page it wants and the helper puts it in the panel of whoever is signed in
 * (a super admin's home is /owner), so each role is tested in its own panel.
 */
trait UsesPanels
{
    /**
     * @param  string  $path  e.g. "/bookings/3", or "" for the panel's home
     */
    protected function panel(string $path = '', ?User $as = null): string
    {
        $user = $as ?? auth()->user();

        return '/'.($user instanceof User ? Panels::homeId($user) : Panels::OWNER).$path;
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
