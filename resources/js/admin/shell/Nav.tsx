import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@ui-sdk/react/collapsible';
import { Tooltip, TooltipContent, TooltipTrigger } from '@ui-sdk/react/tooltip';
import { Link, NavLink, useMatch } from 'react-router-dom';

import { boot } from '../lib/boot';
import { type Me, type NavItem } from '../lib/me';
import { useStored } from '../lib/useStored';
import { screens } from '../screens';
import { cx } from '../ui/cx';
import { Icon, type IconName } from '../ui/icons';

/** Filament slug → the sidebar's icon */
const icons: Record<string, IconName> = {
    dashboard: 'layout-dashboard',
    queue: 'columns-3',
    bookings: 'inbox',
    customers: 'users',
    warranty: 'shield-check',
    'manual-transaction': 'clipboard-check',
    'branch-blocks': 'ban',
    inbox: 'message-circle',
    reviews: 'message-square-text',
    branches: 'store',
    services: 'sparkles',
    'branch-services': 'sparkles',
    'service-categories': 'layout-grid',
    'vehicle-sizes': 'truck',
    'backjob-types': 'wrench',
    photos: 'image',
    videos: 'video',
    'site-settings': 'settings',
    'booking-rules': 'sliders-horizontal',
    'payment-settings': 'qr-code',
    referral: 'gift',
    users: 'users',
    'app-releases': 'smartphone',
    'email-settings': 'at-sign',
    security: 'shield-check',
    meta: 'message-circle',
    'google-sign-in': 'key-round',
};

export const navIcon = (key: string | null): IconName => icons[key ?? ''] ?? 'heart-pulse';

export const isPorted = (item: NavItem) => item.here && item.key !== null && item.key in screens;

const linkClass = 'flex min-h-9 items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm pointer-coarse:min-h-11';

function Entry({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
    const inside = (
        <>
            <Icon name={navIcon(item.key)} className="size-4.5" />
            <span className="min-w-0 flex-1 truncate">{item.label}</span>
            {item.badge && (
                <span title={item.badgeTooltip ?? undefined} className="rounded-full bg-warning px-1.5 text-xs font-semibold text-warning-foreground tabular-nums">
                    {item.badge}
                    {item.badgeTooltip && <span className="sr-only"> {item.badgeTooltip}</span>}
                </span>
            )}
        </>
    );

    if (isPorted(item)) {
        return (
            <NavLink to={`/${item.key}`} onClick={onNavigate} className={({ isActive }) => cx(linkClass, isActive ? 'bg-selected font-semibold text-selected-foreground' : 'hover:bg-muted')}>
                {inside}
            </NavLink>
        );
    }

    return (
        <a href={item.href ?? undefined} className={cx(linkClass, 'hover:bg-muted')}>
            {inside}
            <Icon name="external-link" className="size-3.5 text-muted-foreground" />
            <span className="sr-only">(opens in the current admin)</span>
        </a>
    );
}

/** The folded sidebar: the icon alone, its name on hover and for screen readers */
function RailEntry({ item }: { item: NavItem }) {
    const ported = isPorted(item);
    const active = useMatch({ path: `/${item.key}`, end: false }) !== null && ported;
    // web.css gives a tooltip trigger a border: a rail icon has none
    const className = cx('relative mx-auto flex size-11 items-center justify-center rounded-md border-0 shadow-none', active ? 'bg-selected text-selected-foreground' : 'hover:bg-muted');
    const inside = (
        <>
            <Icon name={navIcon(item.key)} className="size-5" />
            <span className="sr-only">
                {item.label}
                {item.badge && `, ${item.badge} ${item.badgeTooltip ?? ''}`}
                {!ported && ' (opens in the current admin)'}
            </span>
            {item.badge && (
                <span aria-hidden="true" className="absolute top-0 right-0 min-w-4 rounded-full bg-warning px-1 text-center text-[0.625rem] leading-4 font-semibold text-warning-foreground tabular-nums">
                    {item.badge}
                </span>
            )}
        </>
    );

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    ported ? (
                        <Link to={`/${item.key}`} aria-current={active ? 'page' : undefined} className={className}>
                            {inside}
                        </Link>
                    ) : (
                        <a href={item.href ?? undefined} className={className}>
                            {inside}
                        </a>
                    )
                }
            />
            <TooltipContent side="right" sideOffset={8} className="rounded-md bg-foreground px-2 py-1 text-xs font-medium text-background shadow-md">
                {item.label}
            </TooltipContent>
        </Tooltip>
    );
}

export function Brand({ me, showName = true, small = false }: { me: Me; showName?: boolean; small?: boolean }) {
    const name = me.panel.brand.replace(/^826\s+/, '');

    return (
        <span className="flex items-center gap-2">
            {boot.logo ? <img src={boot.logo} alt="826" className={small ? 'h-auto w-11' : 'h-8 w-auto'} /> : <span className="page-title text-lg">826</span>}
            {showName && <span className="text-sm font-semibold">{name}</span>}
        </span>
    );
}

/** The panel's sidebar, as Filament lists it: groups that fold away, or the whole thing folded to icons (`rail`) */
export function Nav({ me, onNavigate, rail = false }: { me: Me; onNavigate?: () => void; rail?: boolean }) {
    const [closed, setClosed] = useStored<string[]>('826.nav.closed', []);

    if (rail) {
        return (
            <nav aria-label="Panel" className="space-y-2">
                {me.navigation.map((group, index) => (
                    <ul key={group.label ?? index} aria-label={group.label ?? undefined} className="m-0 list-none space-y-1 border-border p-0 not-first:border-t not-first:pt-2">
                        {group.items.map((item) => (
                            <li key={item.href ?? item.label}>
                                <RailEntry item={item} />
                            </li>
                        ))}
                    </ul>
                ))}
            </nav>
        );
    }

    return (
        <nav aria-label="Panel" className="space-y-3">
            {me.navigation.map((group, index) => {
                const list = (
                    <ul aria-label={group.label ?? undefined} className="m-0 list-none space-y-0.5 p-0">
                        {group.items.map((item) => (
                            <li key={item.href ?? item.label}>
                                <Entry item={item} onNavigate={onNavigate} />
                            </li>
                        ))}
                    </ul>
                );

                if (!group.label) {
                    return <div key={index}>{list}</div>;
                }

                const label = group.label;
                const open = !closed.includes(label);

                return (
                    <Collapsible key={label} open={open} onOpenChange={(next) => setClosed(next ? closed.filter((each) => each !== label) : [...closed, label])}>
                        <CollapsibleTrigger className="flex w-full cursor-pointer items-center gap-1.5 rounded-md border-0 bg-transparent px-2.5 py-1 text-start text-xs font-semibold text-muted-foreground hover:bg-muted pointer-coarse:min-h-11">
                            <Icon name="chevron-down" className={cx('size-3.5 transition-transform motion-reduce:transition-none', !open && '-rotate-90')} />
                            {label}
                        </CollapsibleTrigger>
                        <CollapsiblePanel>{list}</CollapsiblePanel>
                    </Collapsible>
                );
            })}
        </nav>
    );
}
