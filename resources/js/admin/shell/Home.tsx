import { Link, Navigate, useLocation } from 'react-router-dom';

import { useMe } from '../lib/me';
import { screens } from '../screens';
import { buttonClass } from '../ui/Button';
import { Icon } from '../ui/icons';
import { PageHeader } from '../ui/PageHeader';
import { EmptyState } from '../ui/states';
import { isPorted, navIcon } from './Nav';

/** A panel's home: the queue where the panel has one (as Filament does), else its sections */
export function Home() {
    const { me } = useMe();
    const items = me.navigation.flatMap((group) => group.items);

    if (items.some((item) => item.key === 'queue' && isPorted(item)) && 'queue' in screens) {
        return <Navigate to="/queue" replace />;
    }

    const card = 'flex min-h-14 items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 font-medium hover:border-primary';

    return (
        <>
            <PageHeader title={me.panel.brand} description="Screens are moving here one at a time. Sections marked with an arrow still open in the current admin." />
            <ul className="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 xl:grid-cols-4">
                {items.map((item) => (
                    <li key={item.href ?? item.label}>
                        {isPorted(item) ? (
                            <Link to={`/${item.key}`} className={card}>
                                <Icon name={navIcon(item.key)} className="size-5 text-primary" />
                                {item.label}
                            </Link>
                        ) : (
                            <a href={item.href ?? undefined} className={card}>
                                <Icon name={navIcon(item.key)} className="size-5 text-primary" />
                                <span className="flex-1">{item.label}</span>
                                <Icon name="external-link" className="size-4 text-muted-foreground" />
                                <span className="sr-only">(opens in the current admin)</span>
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </>
    );
}

/** An address with no screen here: the same page in the current admin, if the sidebar has it */
export function NotHere() {
    const { me } = useMe();
    const { pathname } = useLocation();
    const item = me.navigation.flatMap((group) => group.items).find((each) => each.key === pathname.split('/')[1]);

    return (
        <>
            <PageHeader title={item?.label ?? 'Page not found'} />
            <EmptyState
                icon={item ? 'app-window' : 'circle-alert'}
                title={item ? 'This screen is not in the new admin yet' : 'There is nothing at this address'}
                action={
                    <a href={item?.href ?? me.links.classic} className={buttonClass('primary')}>
                        <Icon name="undo-2" />
                        {item ? `Open ${item.label} in the current admin` : 'Open the current admin'}
                    </a>
                }
            />
        </>
    );
}
