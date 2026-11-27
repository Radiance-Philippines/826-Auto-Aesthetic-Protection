import { createContext, useContext } from 'react';

/** GET /console/api/{panel}/me (ConsoleMeController) */
export type NavItem = {
    /** The Filament slug; a screen of the same name here means it is ported */
    key: string | null;
    /** This panel is the one that opens it */
    here: boolean;
    label: string;
    /** Its page in the current (Filament) admin */
    href: string | null;
    badge: string | null;
    badgeTooltip: string | null;
};

export type Me = {
    user: { name: string; email: string; roleLabel: string };
    panel: { id: string; brand: string };
    navigation: { label: string | null; items: NavItem[] }[];
    launcher: { key: string; label: string; glyph: string; state: 'current' | 'available' | 'soon'; href: string | null }[];
    branches: { options: { id: number; name: string }[]; chosen: number | null; locked: boolean; action: string };
    impersonating: { name: string; roleLabel: string; branches: string; stopUrl: string } | null;
    links: { profile: string | null; classic: string; site: string; logout: string };
    /** Where live-update tickets come from; null while they are off */
    live: string | null;
};

export const MeContext = createContext<{ me: Me; reload: () => void } | null>(null);

export function useMe() {
    const value = useContext(MeContext);

    if (!value) {
        throw new Error('useMe outside the shell');
    }

    return value;
}
