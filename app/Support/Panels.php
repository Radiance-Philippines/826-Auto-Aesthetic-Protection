<?php

namespace App\Support;

use App\Models\User;
use App\Providers\Filament\PanelPages;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The admin is four panels over one code base: /owner (the business owner's
 * setup and master dashboard), /sales (sales admins), /operations (detailers
 * and branch managers) and /system (IT backoffice and Radiance). Super admins
 * may open all four, nobody else /system. Each panel's sidebar shows only its
 * own pages (PanelPages::shown), and a page opened in a panel it does not
 * belong to is redirected to the same page in the panel where it lives. The
 * split is organisation, not a security boundary: policies, areas and branch
 * scoping decide what anyone can do. The fifth, /admin, only holds the one
 * sign-in page and the old links: it sends each person to their own panel.
 */
final class Panels
{
    public const OWNER = 'owner';

    public const SALES = 'sales';

    public const OPERATIONS = 'operations';

    public const SYSTEM = 'system';

    /** The old single panel: sign-in, password reset and redirects only */
    public const GATEWAY = 'admin';

    /**
     * The panels each role may open, the first one is home.
     *
     * @var array<string, list<string>>
     */
    private const BY_ROLE = [
        Access::SUPER_ADMIN => [self::OWNER, self::SALES, self::OPERATIONS, self::SYSTEM],
        Access::SALES_ADMIN => [self::SALES],
        Access::OPERATIONS => [self::OPERATIONS],
        Access::BRANCH_MANAGER => [self::OPERATIONS],
    ];

    /**
     * @return list<string>
     */
    public static function idsFor(User $user): array
    {
        return self::BY_ROLE[$user->role] ?? [];
    }

    public static function homeId(User $user): ?string
    {
        return self::idsFor($user)[0] ?? null;
    }

    public static function canOpen(User $user, string $panelId): bool
    {
        return in_array($panelId, self::idsFor($user), true);
    }

    /**
     * Where this person lands after signing in.
     */
    public static function homeUrl(User $user): string
    {
        $id = self::homeId($user);

        return $id ? Filament::getPanel($id)->getUrl() : url('/');
    }

    /**
     * Whether this page or resource is in the panel's own set (what its
     * sidebar shows, and the only pages that open there).
     *
     * @param  class-string  $class
     */
    public static function shows(string $class, string $panelId): bool
    {
        return in_array($class, PanelPages::shown($panelId), true);
    }

    /**
     * The page or resource a route opens, when it is one that belongs to a
     * panel (the profile and the sign-in pages belong to none).
     *
     * @return class-string|null
     */
    public static function placedClass(?RoutingRoute $route): ?string
    {
        $class = $route ? explode('@', $route->getActionName())[0] : null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        if (is_subclass_of($class, ResourcePage::class)) {
            $class = $class::getResource();
        } elseif (! is_subclass_of($class, Page::class)) {
            return null;
        }

        foreach (PanelPages::panels() as $id) {
            if (self::shows($class, $id)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The page this request asked for, in the first of this person's panels
     * (other than the one being left, the first path segment) where it lives:
     * an old /admin/bookings/5 link, /owner/bookings for a sales admin, or
     * /owner/queue for a super admin. Null when none of their panels has it.
     */
    public static function elsewhereUrl(User $user, Request $request): ?string
    {
        [$left, $path] = array_pad(explode('/', trim($request->path(), '/'), 2), 2, '');

        if ($path === '') {
            return null;
        }

        foreach (self::idsFor($user) as $id) {
            $candidate = url(trim(Filament::getPanel($id)->getPath(), '/').'/'.$path);
            $route = $id === $left ? null : self::matchGetRoute($candidate);

            if ($route && (($class = self::placedClass($route)) === null || self::shows($class, $id))) {
                return $candidate.($request->getQueryString() ? '?'.$request->getQueryString() : '');
            }
        }

        return null;
    }

    /**
     * Where to send someone who asked for a page their panel does not have:
     * the same page in their panel that has it, else their home.
     */
    public static function equivalentUrl(User $user, Request $request): string
    {
        return self::elsewhereUrl($user, $request) ?? self::homeUrl($user);
    }

    /**
     * The launcher's link to one panel: the page being looked at in that
     * panel when it has it, else that panel's home.
     */
    public static function switchUrl(string $panelId, Request $request): string
    {
        $home = Filament::getPanel($panelId)->getUrl();
        $path = $request->hasHeader('X-Livewire') ? '' : (explode('/', trim($request->path(), '/'), 2)[1] ?? '');

        if ($path === '') {
            return $home;
        }

        $candidate = url(trim(Filament::getPanel($panelId)->getPath(), '/').'/'.$path);
        $route = self::matchGetRoute($candidate);

        return $route && (($class = self::placedClass($route)) === null || self::shows($class, $panelId))
            ? $candidate.($request->getQueryString() ? '?'.$request->getQueryString() : '')
            : $home;
    }

    private static function matchGetRoute(string $url): ?RoutingRoute
    {
        try {
            // Anything else lands on the site's own not-found page
            $route = Route::getRoutes()->match(Request::create($url));

            return $route->isFallback ? null : $route;
        } catch (HttpExceptionInterface) {
            return null;
        }
    }
}
