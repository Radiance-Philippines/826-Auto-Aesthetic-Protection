import type { ComponentProps } from 'react';
import { Link } from 'react-router-dom';

import { routerBase } from '../lib/boot';
import { useMe } from '../lib/me';
import { screens } from '../screens';

/**
 * A link the server gave as a full address. It stays inside the app when this
 * panel has the screen; otherwise it loads as a page, and the server takes the
 * person to the panel that does.
 */
export function ConsoleLink({ href, children, ...link }: Omit<ComponentProps<'a'>, 'href'> & { href: string }) {
    const { me } = useMe();
    const url = new URL(href, window.location.origin);
    const inside = url.origin === window.location.origin && url.pathname.startsWith(`${routerBase}/`) ? url.pathname.slice(routerBase.length) : null;
    const slug = inside?.split('/')[1] ?? '';
    const here = slug in screens && me.navigation.some((group) => group.items.some((item) => item.key === slug && item.here));

    return inside && here ? (
        <Link {...link} to={inside + url.search}>
            {children}
        </Link>
    ) : (
        <a {...link} href={href}>
            {children}
        </a>
    );
}
