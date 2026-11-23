import { Tabs } from 'expo-router';
import { CalendarDays, CircleUser, Gift, House, Truck } from 'lucide-react-native';
import { fonts } from '@/theme';
import { useTheme } from '@/context/theme';

const icon = (Icon: typeof House) =>
    function TabIcon({ color }: { color: unknown }) {
        return <Icon size={24} color={String(color)} strokeWidth={2} />;
    };

/** Exactly five tabs, each with an icon and a label (the design's bar) */
export default function TabsLayout() {
    const c = useTheme();
    return (
        <Tabs
            screenOptions={{
                headerShown: false,
                tabBarActiveTintColor: c.accent,
                tabBarInactiveTintColor: c.muted,
                tabBarStyle: { backgroundColor: c.bg, borderTopColor: c.line, borderTopWidth: 1 },
                tabBarLabelStyle: { fontFamily: fonts.medium, fontSize: 11 },
                sceneStyle: { backgroundColor: c.bg },
            }}
        >
            <Tabs.Screen name="index" options={{ title: 'Home', tabBarIcon: icon(House), tabBarButtonTestID: 'tab-home' }} />
            <Tabs.Screen name="book" options={{ title: 'Book', tabBarIcon: icon(CalendarDays), tabBarButtonTestID: 'tab-book' }} />
            <Tabs.Screen name="garage" options={{ title: 'Garage', tabBarIcon: icon(Truck), tabBarButtonTestID: 'tab-garage' }} />
            <Tabs.Screen name="rewards" options={{ title: 'Rewards', tabBarIcon: icon(Gift), tabBarButtonTestID: 'tab-rewards' }} />
            <Tabs.Screen name="me" options={{ title: 'Me', tabBarIcon: icon(CircleUser), tabBarButtonTestID: 'tab-me' }} />
        </Tabs>
    );
}
