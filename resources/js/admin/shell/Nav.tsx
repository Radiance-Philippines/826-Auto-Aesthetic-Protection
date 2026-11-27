import { NavLink } from 'react-router-dom';

import { boot } from '../lib/boot';
import { type Me, type NavItem } from '../lib/me';
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

export function Brand({ me, showName = true }: { me: Me; showName?: boolean }) {
    const name = me.panel.brand.replace(/^826\s+/, '');

    return (
        <span className="flex items-center gap-2">
            {boot.logo ? <img src={boot.logo} alt="826" className="h-8 w-auto" /> : <span className="page-title text-lg">826</span>}
            {showName && <span className="text-sm font-semibold">{name}</span>}
        </span>
    );
}

/** The panel's sidebar, as Filament lists it */
export function Nav({ me, onNavigate }: { me: Me; onNavigate?: () => void }) {
    return (
        <nav aria-label="Panel" className="space-y-4">
            {me.navigation.map((group, index) => (
                <div key={group.label ?? index}>
                    {group.label && (
                        <p id={`nav-${index}`} className="px-2.5 pb-1 text-xs font-semibold text-muted-foreground">
                            {group.label}
                        </p>
                    )}
                    <ul aria-labelledby={group.label ? `nav-${index}` : undefined} className="m-0 list-none space-y-0.5 p-0">
                        {group.items.map((item) => (
                            <li key={item.href ?? item.label}>
                                <Entry item={item} onNavigate={onNavigate} />
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
