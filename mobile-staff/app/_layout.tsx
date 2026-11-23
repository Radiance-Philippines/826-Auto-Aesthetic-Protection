import { Archivo_400Regular, Archivo_500Medium, Archivo_600SemiBold, Archivo_700Bold, Archivo_800ExtraBold_Italic, useFonts } from '@expo-google-fonts/archivo';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from '@/context/auth';
import { c } from '@/theme';
import { Button } from '@/ui/Button';
import { Text } from '@/ui/Text';

void SplashScreen.preventAutoHideAsync().catch(() => {});

function Shell() {
    const { status, retry } = useAuth();
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
                <Button label="Try again" onPress={() => void retry()} />
            </View>
        );
    }

    const signedIn = status === 'in';

    return (
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'fade_from_bottom', animationDuration: 220 }}>
            <Stack.Protected guard={!signedIn}>
                <Stack.Screen name="sign-in" />
            </Stack.Protected>
            <Stack.Protected guard={signedIn}>
                <Stack.Screen name="(tabs)" options={{ animation: 'none' }} />
                <Stack.Screen name="unit/[id]" />
                <Stack.Screen name="client/[id]" />
                <Stack.Screen name="thread/[id]" />
            </Stack.Protected>
        </Stack>
    );
}

export default function Root() {
    return (
        <GestureHandlerRootView style={{ flex: 1, backgroundColor: c.bg }}>
            <SafeAreaProvider>
                <StatusBar style="light" />
                <AuthProvider>
                    <Shell />
                </AuthProvider>
            </SafeAreaProvider>
        </GestureHandlerRootView>
    );
}
