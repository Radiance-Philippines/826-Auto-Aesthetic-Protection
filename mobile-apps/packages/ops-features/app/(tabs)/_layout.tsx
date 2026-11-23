import { Tabs } from 'expo-router';
import { useAuth } from '../../src/context/auth';
import { getTeamApp } from '../../src/lib/teamApp';
import { ALL_TABS, TAB_META, visibleTabs } from '../../src/lib/tabs';
import { c, fonts } from '@826/core';

/** The tab bar is the server's list for this person (me.tabs): a route that is not in it is hidden (href null), never shown disabled */
export default function TabsLayout() {
    const { user } = useAuth();
    const shown = visibleTabs(user?.tabs);

    return (
        <Tabs
            screenOptions={{
                headerShown: false,
                tabBarActiveTintColor: getTeamApp().accent,
                tabBarInactiveTintColor: c.muted,
                tabBarStyle: { backgroundColor: c.bg, borderTopColor: c.line, borderTopWidth: 1 },
                tabBarLabelStyle: { fontFamily: fonts.medium, fontSize: 11 },
                sceneStyle: { backgroundColor: c.bg },
            }}
        >
            {ALL_TABS.map((key) => {
                const { title, icon: Icon } = TAB_META[key];

                return (
                    <Tabs.Screen
                        key={key}
                        name={key}
                        options={{
                            title,
                            tabBarButtonTestID: `tab-${key}`,
                            tabBarIcon: ({ color }: { color: unknown }) => <Icon size={24} color={String(color)} strokeWidth={2} />,
                            ...(shown.includes(key) ? {} : { href: null }),
                        }}
                    />
                );
            })}
        </Tabs>
    );
}
