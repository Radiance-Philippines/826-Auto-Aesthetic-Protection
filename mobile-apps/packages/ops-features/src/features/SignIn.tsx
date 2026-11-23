import { IdCard, KeyRound, Lock, LogIn, Mail, ShieldCheck } from 'lucide-react-native';
import { useRef, useState } from 'react';
import { Pressable, TextInput, View } from 'react-native';
import { api, problemText, type SignedIn } from '../api';
import { deviceName } from '../context/auth';
import { getTeamApp, wrongAppText } from '../lib/teamApp';
import { c, fonts, radius, target, Brand, Button, Card, Field, Screen, FormError, Text } from '@826/core';

/** Six boxes over one real input, so paste and the keyboard's one-time-code suggestion work */
export function CodeBoxes({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    const input = useRef<React.ComponentRef<typeof TextInput>>(null);

    return (
        <Pressable accessibilityRole="none" onPress={() => input.current?.focus()} style={{ position: 'relative' }}>
            <View style={{ flexDirection: 'row', gap: 8, justifyContent: 'center' }} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
                {Array.from({ length: 6 }, (_, index) => (
                    <View key={index} style={{ flex: 1, maxWidth: 52, height: 60, borderRadius: radius.md, borderWidth: 1, borderColor: index === value.length ? c.accent : c.line, backgroundColor: c.surface, alignItems: 'center', justifyContent: 'center' }}>
                        <Text weight="bold" style={{ fontSize: 24 }}>
                            {value[index] ?? ''}
                        </Text>
                    </View>
                ))}
            </View>
            <TextInput
                ref={input}
                accessibilityLabel="6-digit code"
                testID="code-input"
                value={value}
                onChangeText={(text) => onChange(text.replace(/\D/g, '').slice(0, 6))}
                keyboardType="number-pad"
                textContentType="oneTimeCode"
                autoComplete="one-time-code"
                maxLength={6}
                style={{ position: 'absolute', inset: 0, opacity: 0.02, color: c.text, fontFamily: fonts.regular }}
            />
        </Pressable>
    );
}

/** S1: email and password, then the two-step check when the account has one */
/** The sentence under the form: the server's own words, and for a wrong app "This app is for 826 Sales. Use 826 Operations." */
export const signInProblem = (result: Parameters<typeof problemText>[0]): string =>
    result.kind === 'forbidden' && result.code === 'app_not_for_role' ? (result.message ?? wrongAppText(result.suggestApp)) : problemText(result);

export function SignInScreen({ onDone }: { onDone: (result: SignedIn) => Promise<void> | void }) {
    const team = getTeamApp();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [challenge, setChallenge] = useState<string | null>(null);
    const [recovery, setRecovery] = useState(false);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const submit = async () => {
        if (!email.trim() || !password) {
            setProblem('Enter your email and password.');

            return;
        }

        setBusy(true);
        setProblem(null);
        const result = await api.signIn(email.trim(), password, deviceName());
        setBusy(false);

        if (result.kind !== 'ok') {
            setProblem(signInProblem(result));
        } else if ('twoFactor' in result.data) {
            setChallenge(result.data.twoFactor.challenge);
        } else {
            await onDone(result.data);
        }
    };

    const verify = async () => {
        if (!challenge) return;
        setBusy(true);
        setProblem(null);
        const result = await api.twoFactor(challenge, code.trim());
        setBusy(false);

        if (result.kind === 'ok') {
            await onDone(result.data);
        } else if (result.kind === 'tooMany' || result.kind === 'notFound') {
            // The server ended this check (too many wrong codes): start again from the password
            setChallenge(null);
            setCode('');
            setProblem(signInProblem(result));
        } else {
            setProblem(signInProblem(result));
        }
    };

    if (challenge) {
        const ready = recovery ? code.trim().length >= 8 : code.length === 6;

        return (
            <Screen contentStyle={{ paddingTop: 72, gap: 20 }}>
                <View style={{ alignItems: 'center' }}>
                    <Brand height={44} />
                </View>
                <Text v="display" accessibilityRole="header" style={{ textAlign: 'center' }}>
                    Two-step check
                </Text>
                <Text color={c.muted} style={{ textAlign: 'center' }}>
                    {recovery ? 'Enter one of your backup codes. Each one works once.' : 'Enter the code from your authenticator app.'}
                </Text>
                {recovery ? <Field label="Backup code" value={code} onChangeText={setCode} autoCapitalize="none" autoCorrect={false} icon={KeyRound} /> : <CodeBoxes value={code} onChange={setCode} />}
                <FormError>{problem}</FormError>
                <Button label="Verify" icon={ShieldCheck} loading={busy} disabled={!ready} onPress={verify} />
                <Button
                    kind="link"
                    label={recovery ? 'Use my authenticator app' : 'Use a backup code'}
                    onPress={() => {
                        setRecovery(!recovery);
                        setCode('');
                        setProblem(null);
                    }}
                />
                <Button
                    kind="link"
                    label="Start over"
                    onPress={() => {
                        setChallenge(null);
                        setCode('');
                        setProblem(null);
                    }}
                />
            </Screen>
        );
    }

    return (
        <Screen contentStyle={{ paddingTop: 72, gap: 20 }}>
            <View style={{ alignItems: 'center' }}>
                <Brand height={44} />
            </View>
            <Text v="display" accessibilityRole="header" style={{ textAlign: 'center' }}>
                {team.label}
            </Text>
            <Text color={c.muted} style={{ textAlign: 'center' }}>
                Sign in with the account you use on the 826 admin.
            </Text>
            <Field label="Email" value={email} onChangeText={setEmail} icon={Mail} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} autoComplete="email" textContentType="emailAddress" />
            <Field label="Password" value={password} onChangeText={setPassword} icon={Lock} secureTextEntry autoCapitalize="none" autoComplete="password" textContentType="password" onSubmitEditing={submit} />
            <FormError>{problem}</FormError>
            <Button label="Sign in" icon={LogIn} loading={busy} onPress={submit} />
            <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
                <IdCard size={20} color={c.accent} />
                <Text v="small" style={{ flex: 1 }}>
                    Your role decides what you see: head detailer, sales, branch manager or owner.
                </Text>
            </Card>
            <View style={{ minHeight: target }} />
        </Screen>
    );
}
