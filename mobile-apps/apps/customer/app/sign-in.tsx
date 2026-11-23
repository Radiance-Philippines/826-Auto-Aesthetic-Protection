import { useLocalSearchParams, useRouter } from 'expo-router';
import { KeyRound, LogIn, UserPlus, UserRound } from 'lucide-react-native';
import { useRef, useState } from 'react';
import { View } from 'react-native';
import { api, type ApiResult, type SignInResult } from '@/api';
import { deviceName, useAuth } from '@/context/auth';
import { useCatalog } from '@/context/catalog';
import { useTheme, HEADER, Button, Field, type InputRef, Screen, Skeleton, ErrorState, FormError, Text } from '@826/core';
import { OrDivider, providersFor, ResendVerification, SocialButtons } from '@/features/Account';
import { authProblem, emailOk, type AuthProblem } from '@/lib/account';
import { DEMO, MOCK } from '@/lib/config';
import { PasswordField } from '@/ui/PasswordField';

export default function SignInScreen() {
    const c = useTheme();
    const router = useRouter();
    const { accept } = useAuth();
    const { catalog, error, reload } = useCatalog();
    const params = useLocalSearchParams<{ email?: string }>();
    const [email, setEmail] = useState(typeof params.email === 'string' ? params.email : '');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState<'email' | 'demo' | null>(null);
    const [problem, setProblem] = useState<AuthProblem | null>(null);
    const [other, setOther] = useState<string | null>(null);
    const passwordInput = useRef<InputRef>(null);

    const signIn = catalog?.signIn;
    const providers = providersFor(signIn);
    const showDemo = (DEMO || MOCK) && !!signIn?.demo;
    const showOr = providers.google || providers.apple || showDemo;

    const finish = async (result: ApiResult<SignInResult>) => {
        if (result.kind === 'ok') await accept(result.data);
        else setOther(authProblem(result).form);
    };

    const submit = async () => {
        if (busy) return;

        if (!emailOk(email) || !password) {
            setProblem({ form: !emailOk(email) ? 'Enter the email you signed up with.' : 'Enter your password.', fields: {}, resend: null, unavailable: false });

            return;
        }

        setBusy('email');
        setProblem(null);
        setOther(null);

        try {
            const result = await api.login(email.trim(), password, deviceName());

            if (result.kind === 'ok') await accept(result.data);
            else setProblem(authProblem(result, email.trim(), { login: true }));
        } finally {
            setBusy(null);
        }
    };

    const demo = async () => {
        setBusy('demo');
        setProblem(null);
        setOther(null);

        try {
            await finish(await api.signInDemo(deviceName()));
        } catch {
            setOther('Sign-in didn’t go through. Try again.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <Screen brand contentStyle={{ paddingTop: HEADER.signInTop, gap: 20 }}>
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
                    <View style={{ gap: 12 }} testID="sign-in-loading">
                        <Skeleton height={56} />
                        <Skeleton height={56} />
                        <Skeleton height={48} />
                    </View>
                )
            ) : (
                <View style={{ gap: 16 }}>
                    <View style={{ gap: 12 }}>
                        <Field
                            label="Email"
                            value={email}
                            onChangeText={setEmail}
                            keyboardType="email-address"
                            autoComplete="email"
                            textContentType="emailAddress"
                            autoCapitalize="none"
                            autoCorrect={false}
                            returnKeyType="next"
                            blurOnSubmit={false}
                            onSubmitEditing={() => passwordInput.current?.focus()}
                        />
                        <PasswordField label="Password" value={password} onChangeText={setPassword} autoComplete="current-password" textContentType="password" returnKeyType="go" onSubmitEditing={() => void submit()} inputRef={passwordInput} />
                        <FormError>{problem?.form}</FormError>
                        {problem?.resend ? <ResendVerification email={problem.resend} /> : null}
                        <Button icon={LogIn} label="Sign in" loading={busy === 'email'} disabled={busy !== null} onPress={() => void submit()} testID="email-sign-in" />
                        <View style={{ alignItems: 'center' }}>
                            <Button kind="link" icon={KeyRound} label="Forgot password?" onPress={() => router.push('/forgot-password')} testID="forgot" />
                        </View>
                    </View>

                    {showOr ? (
                        <>
                            <OrDivider />
                            <View style={{ gap: 12 }}>
                                <FormError>{other}</FormError>
                                <SocialButtons verb="Sign in" signIn={signIn} disabled={busy !== null} onSignedIn={accept} onProblem={setOther} />
                                {showDemo ? <Button kind="secondary" icon={UserRound} label="Try the demo account" loading={busy === 'demo'} disabled={busy !== null} onPress={() => void demo()} testID="demo" /> : null}
                                {showDemo ? <Text v="small">Demo: sample customer and sample schedules. Nothing you send here is saved.</Text> : null}
                            </View>
                        </>
                    ) : null}

                    <View style={{ alignItems: 'center' }}>
                        <Button kind="link" icon={UserPlus} label="New here? Create an account" onPress={() => router.push('/register')} testID="create-account" />
                    </View>
                </View>
            )}

            {/* No link: the team app has no store page yet */}
            <Text v="small" style={{ marginTop: 8 }}>
                Are you on the team? Use the 826 Team app.
            </Text>
        </Screen>
    );
}
