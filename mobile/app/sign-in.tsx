import { Apple, LogIn, UserRound } from 'lucide-react-native';
import { useState } from 'react';
import { View } from 'react-native';
import { api, problemText, type ApiResult, type SignInResult } from '@/api';
import { deviceName, useAuth } from '@/context/auth';
import { useCatalog } from '@/context/catalog';
import { appleAvailable, appleIdentity } from '@/lib/apple';
import { DEMO, MOCK } from '@/lib/config';
import { googleConfigured, googleIdToken } from '@/lib/google';
import { useTheme } from '@/context/theme';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Screen } from '@/ui/Screen';
import { Skeleton } from '@/ui/Skeleton';
import { ErrorState, FormError } from '@/ui/States';
import { Text } from '@/ui/Text';

export default function SignInScreen() {
    const c = useTheme();
    const { accept } = useAuth();
    const { catalog, error, reload } = useCatalog();
    const [busy, setBusy] = useState<'google' | 'apple' | 'demo' | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    const finish = async (result: ApiResult<SignInResult>) => {
        if (result.kind === 'ok') await accept(result.data);
        else setProblem(problemText(result));
    };

    const run = (which: 'google' | 'apple' | 'demo', task: () => Promise<ApiResult<SignInResult> | null>) => async () => {
        setBusy(which);
        setProblem(null);

        try {
            const result = await task();
            // null: the person closed Google's or Apple's sheet
            if (result) await finish(result);
        } catch {
            setProblem('Sign-in didn’t go through. Try again.');
        } finally {
            setBusy(null);
        }
    };

    const signIn = catalog?.signIn;
    const showGoogle = !!signIn?.google && googleConfigured();
    const showApple = !!signIn?.apple && appleAvailable();
    const showDemo = (DEMO || MOCK) && !!signIn?.demo;
    const none = !!signIn && !showGoogle && !showApple && !showDemo;

    return (
        <Screen brand contentStyle={{ paddingTop: 48, gap: 20 }}>
            <View style={{ gap: 12, marginTop: 24 }}>
                <Text v="display" style={{ fontSize: 34, lineHeight: 42 }} accessibilityRole="header">
                    Sign in
                </Text>
                <Text color={c.muted}>Book a drop-off date at 826 Auto Aesthetic, follow your unit while it’s in the shop, and keep your warranty and rewards in one place.</Text>
            </View>

            {!catalog ? (
                error ? (
                    <ErrorState result={error} onRetry={reload} />
                ) : (
                    <Skeleton height={56} />
                )
            ) : (
                <View style={{ gap: 12 }}>
                    <FormError>{problem}</FormError>
                    {showGoogle ? <Button icon={LogIn} label="Sign in with Google" loading={busy === 'google'} disabled={busy !== null} onPress={run('google', async () => {
                        const token = await googleIdToken();

                        return token ? api.signInGoogle(token, deviceName()) : null;
                    })} testID="google" /> : null}
                    {showApple ? <Button kind="secondary" icon={Apple} label="Sign in with Apple" loading={busy === 'apple'} disabled={busy !== null} onPress={run('apple', async () => {
                        const identity = await appleIdentity();

                        return identity ? api.signInApple(identity.identityToken, deviceName(), identity.fullName) : null;
                    })} testID="apple" /> : null}
                    {showDemo ? <Button kind="secondary" icon={UserRound} label="Try the demo account" loading={busy === 'demo'} disabled={busy !== null} onPress={run('demo', () => api.signInDemo(deviceName()))} testID="demo" /> : null}
                    {none ? (
                        <Card>
                            <Text color={c.muted}>Sign-in isn’t open on this build yet. Call or message the branch to book.</Text>
                        </Card>
                    ) : null}
                    {showDemo ? <Text v="small">Demo: sample customer and sample schedules. Nothing you send here is saved.</Text> : null}
                </View>
            )}

            {/* No link: the team app has no store page yet */}
            <Text v="small" style={{ marginTop: 8 }}>
                Are you on the team? Use the 826 Team app.
            </Text>
        </Screen>
    );
}
