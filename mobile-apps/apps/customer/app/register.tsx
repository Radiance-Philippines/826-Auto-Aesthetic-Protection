import { useRouter } from 'expo-router';
import { LogIn, MailCheck, UserPlus } from 'lucide-react-native';
import { useRef, useState } from 'react';
import { View } from 'react-native';
import { api } from '@/api';
import { deviceName, useAuth } from '@/context/auth';
import { useCatalog } from '@/context/catalog';
import { useTheme, Button, Card, Field, type InputRef, Screen, Skeleton, ErrorState, FormError, Text } from '@826/core';
import { OrDivider, providersFor, RESEND_SECONDS, ResendVerification, SocialButtons } from '@/features/Account';
import { authProblem, emailOk, PASSWORD_RULE, passwordProblem, SIGN_UP_UNAVAILABLE } from '@/lib/account';
import { phoneOk } from '@/lib/wizard';
import { PasswordField } from '@/ui/PasswordField';

export default function RegisterScreen() {
    const c = useTheme();
    const router = useRouter();
    const { accept } = useAuth();
    const { catalog, error, reload } = useCatalog();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [phone, setPhone] = useState('');
    const [fields, setFields] = useState<Record<string, string>>({});
    const [form, setForm] = useState<string | null>(null);
    const [other, setOther] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [unavailable, setUnavailable] = useState(false);
    /** The address a verification email went to */
    const [sentTo, setSentTo] = useState<string | null>(null);
    const emailInput = useRef<InputRef>(null);
    const passwordInput = useRef<InputRef>(null);
    const phoneInput = useRef<InputRef>(null);

    const providers = providersFor(catalog?.signIn);
    const goSignIn = (address?: string) => router.replace(address ? { pathname: '/sign-in', params: { email: address } } : '/sign-in');

    const submit = async () => {
        if (busy) return;

        const found: Record<string, string> = {};
        if (name.trim().length < 2) found.name = 'Enter your name.';
        if (!emailOk(email)) found.email = 'Enter a valid email address.';
        const weak = passwordProblem(password, email);
        if (weak) found.password = weak;
        if (phone.trim() && !phoneOk(phone)) found.phone = 'Enter your mobile number, like 0917 123 4567.';
        setFields(found);
        setForm(null);
        if (Object.keys(found).length) return;

        setBusy(true);

        try {
            const result = await api.register({ name: name.trim(), email: email.trim(), password, ...(phone.trim() ? { phone: phone.trim() } : {}) }, deviceName());

            if (result.kind !== 'ok') {
                const problem = authProblem(result, email.trim());
                setFields(problem.fields);
                setForm(problem.form);
                setUnavailable(problem.unavailable);

                return;
            }

            if (result.data.token && result.data.guest) {
                // The demo (or a machine with no mailbox) verifies at once and signs in
                await accept({ token: result.data.token, guest: result.data.guest });
            } else {
                setSentTo(result.data.email ?? email.trim());
            }
        } finally {
            setBusy(false);
        }
    };

    if (sentTo) {
        return (
            <Screen back="Sign in" title="Check your email">
                <Card style={{ gap: 12 }}>
                    <MailCheck size={28} color={c.accent} />
                    <Text>{`We sent a link to ${sentTo}. Open it to verify your email, then come back here and sign in.`}</Text>
                    <Text v="small">It can take a minute. Look in spam too. The link works for 24 hours.</Text>
                </Card>
                <ResendVerification email={sentTo} startLeft={RESEND_SECONDS} />
                <Button icon={LogIn} label="I verified, sign in" onPress={() => goSignIn(sentTo)} testID="verified" />
            </Screen>
        );
    }

    return (
        <Screen back="Sign in" title="Create account">
            <Text color={c.muted}>Book a drop-off date at 826, follow your unit while it’s in the shop, and keep your warranty and rewards in one place.</Text>

            {!catalog ? (
                error ? (
                    <ErrorState result={error} onRetry={reload} />
                ) : (
                    <View style={{ gap: 12 }} testID="register-loading">
                        <Skeleton height={56} />
                        <Skeleton height={56} />
                        <Skeleton height={56} />
                        <Skeleton height={48} />
                    </View>
                )
            ) : (
                <View style={{ gap: 16 }}>
                    {unavailable ? (
                        <Card testID="email-unavailable" style={{ gap: 4 }}>
                            <Text accessibilityRole="alert" weight="semi">
                                Use Google
                            </Text>
                            <Text color={c.muted}>{SIGN_UP_UNAVAILABLE}</Text>
                        </Card>
                    ) : (
                        <View style={{ gap: 12 }}>
                            <Field label="Name" value={name} onChangeText={setName} autoCapitalize="words" autoComplete="name" textContentType="name" returnKeyType="next" blurOnSubmit={false} onSubmitEditing={() => emailInput.current?.focus()} error={fields.name} />
                            <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoComplete="email" textContentType="emailAddress" autoCapitalize="none" autoCorrect={false} returnKeyType="next" blurOnSubmit={false} onSubmitEditing={() => passwordInput.current?.focus()} inputRef={emailInput} error={fields.email} />
                            <PasswordField label="Password" value={password} onChangeText={setPassword} autoComplete="new-password" textContentType="newPassword" returnKeyType="next" blurOnSubmit={false} onSubmitEditing={() => phoneInput.current?.focus()} inputRef={passwordInput} hint={PASSWORD_RULE} error={fields.password} />
                            <Field label="Mobile number" optional value={phone} onChangeText={setPhone} keyboardType="phone-pad" autoComplete="tel" textContentType="telephoneNumber" placeholder="0917 123 4567" returnKeyType="done" onSubmitEditing={() => void submit()} inputRef={phoneInput} error={fields.phone} />
                            <FormError>{form}</FormError>
                            <Button icon={UserPlus} label="Create account" loading={busy} onPress={() => void submit()} testID="email-register" />
                        </View>
                    )}

                    {providers.google || providers.apple ? (
                        <>
                            {unavailable ? null : <OrDivider />}
                            <View style={{ gap: 12 }}>
                                <FormError>{other}</FormError>
                                <SocialButtons verb="Create account" signIn={catalog.signIn} disabled={busy} onSignedIn={accept} onProblem={setOther} />
                            </View>
                        </>
                    ) : null}

                    <View style={{ alignItems: 'center' }}>
                        <Button kind="link" icon={LogIn} label="Already have an account? Sign in" onPress={() => goSignIn()} testID="to-sign-in" />
                    </View>
                </View>
            )}
        </Screen>
    );
}
