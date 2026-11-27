<?php

namespace App\Support;

use App\Filament\Concerns\BlockedWhileImpersonating;
use App\Providers\Filament\PanelPages;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Filament\Panel;
use Filament\Resources\Resource;

/**
 * The React admin (/console/{panel}), beside the Filament panels until the
 * cutover. A screen is known by its Filament slug ("queue", "vehicle-sizes"):
 * the Filament page or resource stays the one place that says which panel
 * shows it, what the sidebar calls it and who may open it.
 */
final class AdminConsole
{
    /**
     * The pages and resources one panel shows, by slug.
     *
     * @return array<string, class-string>
     */
    public static function screens(Panel $panel): array
    {
        return collect([...$panel->getPages(), ...$panel->getResources()])
            ->filter(fn (string $class) => Panels::shows($class, $panel->getId()))
            ->mapWithKeys(fn (string $class) => [$class::getSlug($panel) => $class])
            ->all();
    }

    /**
     * Whether any panel has a screen by this slug.
     */
    public static function exists(string $slug): bool
    {
        return collect(PanelPages::panels())->contains(fn (string $id) => isset(self::screens(Filament::getPanel($id))[$slug]));
    }

    /**
     * 403 unless the signed-in person may open this page or resource now.
     *
     * @param  class-string  $class
     */
    public static function authorize(string $class): void
    {
        abort_if(Impersonation::active() && self::blockedWhileImpersonating($class), 403, 'Not available while viewing as someone else.');
        abort_unless($class::canAccess(), 403, 'Your role doesn’t include this.');
    }

    /**
     * The panel's sidebar exactly as Filament builds it (labels, groups,
     * badges, who sees what), with each entry's slug and whether this panel
     * is the one that shows it. Needs the panel to be the current one.
     *
     * @return list<array{label: string|null, items: list<array{key: string|null, here: bool, label: string, href: string|null, badge: string|null, badgeTooltip: string|null}>}>
     */
    public static function navigation(Panel $panel): array
    {
        // Built once per request by Filament; a second panel in the same process must not get the first one's
        app()->forgetInstance(NavigationManager::class);

        // Every registered page, not only the panel's own: Filament lists "Services" in more than one sidebar
        $slugs = [];

        foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
            if ($class::shouldRegisterNavigation()) {
                $slugs[$class::getUrl(panel: $panel->getId())] = $class::getSlug($panel);
            }
        }

        $here = self::screens($panel);

        return collect($panel->getNavigation())->map(fn (NavigationGroup $group) => [
            'label' => $group->getLabel() === null ? null : (string) $group->getLabel(),
            'items' => collect($group->getItems())->map(fn (NavigationItem $item) => [
                'key' => $slugs[$item->getUrl()] ?? null,
                'here' => isset($here[$slugs[$item->getUrl()] ?? '']),
                'label' => (string) $item->getLabel(),
                'href' => $item->getUrl(),
                'badge' => $item->getBadge() === null ? null : (string) $item->getBadge(),
                'badgeTooltip' => $item->getBadge() === null ? null : (string) $item->getBadgeTooltip(),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Settings pages and users are closed while a super admin views as someone else.
     *
     * @param  class-string  $class
     */
    private static function blockedWhileImpersonating(string $class): bool
    {
        $pages = is_subclass_of($class, Resource::class) ? array_map(fn ($page) => $page->getPage(), array_values($class::getPages())) : [$class];

        return collect($pages)->contains(fn (string $page) => in_array(BlockedWhileImpersonating::class, class_uses_recursive($page), true));
    }
}
