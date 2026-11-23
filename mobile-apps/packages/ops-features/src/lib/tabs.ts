import type { LucideIcon } from 'lucide-react-native';
import { CircleUser, Gauge, Inbox, Layers, ServerCog, Users, Wallet } from 'lucide-react-native';
import type { TabKey } from '../api/types';

export const TAB_META: Record<TabKey, { title: string; icon: LucideIcon }> = {
    queue: { title: 'Queue', icon: Layers },
    today: { title: 'Today', icon: Wallet },
    clients: { title: 'Clients', icon: Users },
    inbox: { title: 'Inbox', icon: Inbox },
    overview: { title: 'Overview', icon: Gauge },
    system: { title: 'System', icon: ServerCog },
    me: { title: 'Me', icon: CircleUser },
};

/** Every route the tab bar can hold, in the file order of app/(tabs) */
export const ALL_TABS = Object.keys(TAB_META) as TabKey[];

/**
 * The tabs a person sees: exactly the server's list, in its order, keeping only the ones this
 * app knows. Me is always last. At most five (the tab bar's limit).
 */
export function visibleTabs(fromServer: string[] | undefined): TabKey[] {
    const known = (fromServer ?? []).map((tab) => tab.toLowerCase()).filter((tab): tab is TabKey => tab in TAB_META && tab !== 'me');

    return [...new Set(known)].slice(0, 4).concat('me');
}

/** The tab a person lands on after signing in */
export const firstTab = (fromServer: string[] | undefined): TabKey => visibleTabs(fromServer)[0];
