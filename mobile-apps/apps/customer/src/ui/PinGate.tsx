import { Button, Field, problemText, Text, useTheme } from '@826/core';
import * as SplashScreen from 'expo-splash-screen';
import { LockOpen } from 'lucide-react-native';
import { useEffect, useState, type ReactNode } from 'react';
import { View } from 'react-native';
import { api } from '@/api';
import { setLockedHandler, setPin } from '@/lib/pin';

/** Shows the team PIN screen instead of the app when the server asks for it */
export function PinGate({ children }: { children: ReactNode }) {
    const [locked, setLocked] = useState(false);

    useEffect(() => {
        setLockedHandler(() => setLocked(true));

        return () => setLockedHandler(undefined);
    }, []);

    // The app mounts again after unlocking, so everything it needs is fetched again
    return locked ? <TeamPin onOpen={() => setLocked(false)} /> : <>{children}</>;
}

export function TeamPin({ onOpen }: { onOpen: () => void }) {
    const c = useTheme();
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        void SplashScreen.hideAsync().catch(() => {});
    }, []);

    const open = async () => {
        if (!code.trim()) {
            setError('Enter the PIN first.');

            return;
        }

        setBusy(true);
        await setPin(code.trim());
        const result = await api.catalog();
        setBusy(false);

        if (result.kind === 'ok') onOpen();
        else setError(problemText(result));
    };

    return (
        <View style={{ flex: 1, backgroundColor: c.bg, justifyContent: 'center', gap: 16, padding: 32 }}>
            <Text v="title">Team testing</Text>
            <Text color={c.muted}>826 isn’t open yet. Enter the team PIN to try it.</Text>
            <Field label="Team PIN" value={code} onChangeText={setCode} keyboardType="number-pad" secureTextEntry hint="Ask the 826 team for it." error={error} returnKeyType="go" onSubmitEditing={() => void open()} />
            <Button testID="team-pin-open" icon={LockOpen} label="Open" loading={busy} onPress={() => void open()} />
        </View>
    );
}
