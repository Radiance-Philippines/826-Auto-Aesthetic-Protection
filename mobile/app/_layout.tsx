import { Archivo_400Regular, Archivo_500Medium, Archivo_600SemiBold, Archivo_700Bold, Archivo_800ExtraBold_Italic, useFonts } from '@expo-google-fonts/archivo';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import * as SystemUI from 'expo-system-ui';
import { useEffect } from 'react';
import { View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { RefreshCw } from 'lucide-react-native';
import { AuthProvider, useAuth } from '@/context/auth';
import { CatalogProvider } from '@/context/catalog';
import { ThemeProvider, useTheme } from '@/context/theme';
import { Button } from '@/ui/Button';
import { useReducedMotion } from '@/ui/motion';
import { Text } from '@/ui/Text';

void SplashScreen.preventAutoHideAsync().catch(() => {});

function Shell() {
    const c = useTheme();
    const { status, retry } = useAuth();
    const motion = useReducedMotion();
    const [fonts] = useFonts({ Archivo_400Regular, Archivo_500Medium, Archivo_600SemiBold, Archivo_700Bold, Archivo_800ExtraBold_Italic });
    const ready = fonts && status !== 'loading';

    useEffect(() => {
        if (ready) void SplashScreen.hideAsync().catch(() => {});
    }, [ready]);

    if (!ready) {
        return null;
    }

    if (status === 'offline') {
        return (
            <View style={{ flex: 1, backgroundColor: c.bg, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 32 }}>
                <Text v="title" style={{ textAlign: 'center' }}>
                    No connection
                </Text>
                <Text color={c.muted} style={{ textAlign: 'center' }}>
                    We couldn’t reach 826. Check your internet and try again.
                </Text>
                <Button icon={RefreshCw} label="Try again" onPress={() => void retry()} />
            </View>
        );
    }

    const signedIn = status === 'in';

    return (
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: motion ? 'none' : 'fade_from_bottom', animationDuration: 220 }}>
            <Stack.Protected guard={!signedIn}>
                <Stack.Screen name="sign-in" />
            </Stack.Protected>
            <Stack.Protected guard={signedIn}>
                <Stack.Screen name="(tabs)" options={{ animation: 'none' }} />
                <Stack.Screen name="booking/[id]" />
                <Stack.Screen name="vehicle/[id]" />
                <Stack.Screen name="review/[id]" />
                <Stack.Screen name="bookings" />
                <Stack.Screen name="warranty" />
                <Stack.Screen name="work" />
                <Stack.Screen name="home-service" />
            </Stack.Protected>
        </Stack>
    );
}

/** The root view, status bar and system UI take the scheme's background and icon colours */
function Frame() {
    const c = useTheme();

    useEffect(() => {
        void SystemUI.setBackgroundColorAsync(c.bg).catch(() => {});
    }, [c.bg]);

    return (
        <GestureHandlerRootView style={{ flex: 1, backgroundColor: c.bg }}>
            <SafeAreaProvider>
                <StatusBar style={c.scheme === 'dark' ? 'light' : 'dark'} />
                <CatalogProvider>
                    <AuthProvider>
                        <Shell />
                    </AuthProvider>
                </CatalogProvider>
            </SafeAreaProvider>
        </GestureHandlerRootView>
    );
}

export default function Root() {
    return (
        <ThemeProvider>
            <Frame />
        </ThemeProvider>
    );
}
