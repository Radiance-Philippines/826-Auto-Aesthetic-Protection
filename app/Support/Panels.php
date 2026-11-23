<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The admin is three panels over one code base: /owner (super admins, who
 * may open all three), /sales (sales admins) and /operations (detailers and
 * branch managers). The split is organisation, not a security boundary:
 * policies, areas and branch scoping decide what anyone can do. The fourth
 * panel, /admin, only holds the one sign-in page and the old links: it sends
 * each person to their own panel.
 */
final class Panels
{
    public const OWNER = 'owner';

    public const SALES = 'sales';

    public const OPERATIONS = 'operations';

    /** The old single panel: sign-in, password reset and redirects only */
    public const GATEWAY = 'admin';

    /**
     * The panels each role may open, the first one is home.
     *
     * @var array<string, list<string>>
     */
    private const BY_ROLE = [
        Access::SUPER_ADMIN => [self::OWNER, self::SALES, self::OPERATIONS],
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
     * The page this request asked for, in this person's own panel when it
     * exists there (an old /admin/bookings/5 link, or /owner/bookings for a
     * sales admin), else their panel's home. The first path segment is the
     * panel being left.
     */
    public static function equivalentUrl(User $user, Request $request): string
    {
        $id = self::homeId($user);

        if ($id === null) {
            return url('/');
        }

        $path = explode('/', trim($request->path(), '/'), 2)[1] ?? '';

        $candidate = url(trim(Filament::getPanel($id)->getPath(), '/').'/'.$path);

        if ($path !== '' && self::hasGetRoute($candidate)) {
            return $candidate.($request->getQueryString() ? '?'.$request->getQueryString() : '');
        }

        return self::homeUrl($user);
    }

    private static function hasGetRoute(string $url): bool
    {
        try {
            // Anything else lands on the site's own not-found page
            return ! Route::getRoutes()->match(Request::create($url))->isFallback;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }
}
