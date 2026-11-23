import { Grip } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Pressable, View } from 'react-native';
import { api, type AppTile, type StaffUser } from '../api';
import { glyphFor, openApp, worthShowing } from '../lib/apps';
import { c, radius, Sheet, target, Text } from '@826/core';

/** One answer per signed-in person, shared by every tab's button (the tabs stay mounted, each would ask again) */
let cached: { userId: number; apps: AppTile[] } | null = null;

/** The drawer's tiles from GET /api/staff/apps; null until they arrive or when the call fails (then there is no button) */
export function useApps(user: StaffUser): AppTile[] | null {
    const [apps, setApps] = useState<AppTile[] | null>(cached?.userId === user.id ? cached.apps : null);

    useEffect(() => {
        let alive = true;

        void api.apps().then((result) => {
            if (result.kind !== 'ok') return;
            cached = { userId: user.id, apps: result.data.apps };
            if (alive) setApps(result.data.apps);
        });

        return () => {
            alive = false;
        };
    }, [user.id]);

    return apps;
}

/** The grid button of a tab's header: opens the app drawer. Not there for someone with a single app and nothing planned. */
export function AppsButton({ user }: { user: StaffUser }) {
    const apps = useApps(user);
    const [open, setOpen] = useState(false);

    if (!worthShowing(apps)) return null;

    return (
        <>
            <Pressable accessibilityRole="button" accessibilityLabel="Apps" onPress={() => setOpen(true)} style={{ width: target, height: target, alignItems: 'center', justifyContent: 'center', marginRight: -12 }}>
                <Grip size={24} color={c.text} strokeWidth={2.2} />
            </Pressable>
            <AppDrawer open={open} onClose={() => setOpen(false)} apps={apps} />
        </>
    );
}

const PROBLEM = (label: string) => `${label} isn’t installed on this phone, and there is no download for it yet.`;

/** The sheet: a grid of icon tiles with the name below, like the web launcher. Soon tiles are disabled. */
export function AppDrawer({ open, onClose, apps }: { open: boolean; onClose: () => void; apps: AppTile[] }) {
    const [problem, setProblem] = useState<string | null>(null);

    const close = () => {
        setProblem(null);
        onClose();
    };

    const press = async (tile: AppTile) => {
        if (tile.state === 'current') return close();

        const result = await openApp(tile);

        if (result === 'none') setProblem(PROBLEM(tile.label));
        else close();
    };

    return (
        <Sheet open={open} onClose={close} title="Apps">
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', marginHorizontal: -4 }}>
                {apps.map((tile) => (
                    <Tile key={tile.key} tile={tile} onPress={() => void press(tile)} />
                ))}
            </View>
            {problem ? (
                <Text v="small" color={c.danger} accessibilityLiveRegion="polite">
                    {problem}
                </Text>
            ) : null}
        </Sheet>
    );
}

function Tile({ tile, onPress }: { tile: AppTile; onPress: () => void }) {
    const Icon = glyphFor(tile.icon);
    const soon = tile.state === 'soon';
    const current = tile.state === 'current';

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={soon ? `${tile.label}, coming soon` : current ? `${tile.label}, current app` : tile.label}
            accessibilityState={{ disabled: soon, selected: current }}
            disabled={soon}
            onPress={onPress}
            style={{ width: '33.333%', minHeight: target, alignItems: 'center', gap: 6, paddingVertical: 8, paddingHorizontal: 4, opacity: soon ? 0.55 : 1 }}
        >
            <View style={{ width: 56, height: 56, borderRadius: radius.lg, alignItems: 'center', justifyContent: 'center', backgroundColor: c.surface2, borderWidth: current ? 2 : 0, borderColor: c.accent }}>
                <Icon size={26} color={current ? c.accent : c.text} />
            </View>
            <Text weight="medium" color={current ? c.accent : c.text} style={{ fontSize: 13, lineHeight: 17, textAlign: 'center' }}>
                {tile.label}
            </Text>
            {soon ? (
                <View style={{ borderRadius: radius.pill, backgroundColor: c.tone.neutral.bg, paddingHorizontal: 8, marginTop: -2 }}>
                    <Text weight="semi" color={c.muted} style={{ fontSize: 11, lineHeight: 18 }}>
                        Soon
                    </Text>
                </View>
            ) : null}
        </Pressable>
    );
}
