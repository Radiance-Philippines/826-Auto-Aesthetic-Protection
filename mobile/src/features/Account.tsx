import { Apple, Check, LogIn, Mail } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { api, problemText, type ApiResult, type Catalog, type SignInResult } from '@/api';
import { deviceName } from '@/context/auth';
import { useTheme } from '@/context/theme';
import { appleAvailable, appleIdentity } from '@/lib/apple';
import { googleConfigured, googleIdToken } from '@/lib/google';
import { Button } from '@/ui/Button';
import { FormError } from '@/ui/States';
import { Text } from '@/ui/Text';

/** The server needs this long between two emails to one address */
export const RESEND_SECONDS = 60;

/** "or", between the email form and the other ways in */
export function OrDivider() {
    const c = useTheme();

    return (
        <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
            <View style={{ flex: 1, height: 1, backgroundColor: c.line }} />
            <Text v="small">or</Text>
            <View style={{ flex: 1, height: 1, backgroundColor: c.line }} />
        </View>
    );
}

/** Which of Google and Apple this build and the server both offer */
export const providersFor = (signIn: Catalog['signIn'] | undefined) => ({ google: !!signIn?.google && googleConfigured(), apple: !!signIn?.apple && appleAvailable() });

/**
 * "<verb> with Google" and, on iOS, "<verb> with Apple". The same server call signs in or opens the account.
 * Only the providers the server turned on and this build can run.
 */
export function SocialButtons({ verb, signIn, disabled, onSignedIn, onProblem }: { verb: 'Sign in' | 'Create account'; signIn: Catalog['signIn'] | undefined; disabled?: boolean; onSignedIn: (result: SignInResult) => Promise<void>; onProblem: (text: string | null) => void }) {
    const [busy, setBusy] = useState<'google' | 'apple' | null>(null);
    const { google, apple } = providersFor(signIn);

    const run = (which: 'google' | 'apple', task: () => Promise<ApiResult<SignInResult> | null>) => async () => {
        setBusy(which);
        onProblem(null);

        try {
            const result = await task();

            // null: the person closed Google's or Apple's sheet
            if (result?.kind === 'ok') await onSignedIn(result.data);
            else if (result) onProblem(problemText(result));
        } catch {
            onProblem('Sign-in didn’t go through. Try again.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            {google ? (
                <Button
                    icon={LogIn}
                    kind="secondary"
                    label={`${verb} with Google`}
                    loading={busy === 'google'}
                    disabled={disabled || busy !== null}
                    onPress={run('google', async () => {
                        const token = await googleIdToken();

                        return token ? api.signInGoogle(token, deviceName()) : null;
                    })}
                    testID="google"
                />
            ) : null}
            {apple ? (
                <Button
                    icon={Apple}
                    kind="secondary"
                    label={`${verb} with Apple`}
                    loading={busy === 'apple'}
                    disabled={disabled || busy !== null}
                    onPress={run('apple', async () => {
                        const identity = await appleIdentity();

                        return identity ? api.signInApple(identity.identityToken, deviceName(), identity.fullName) : null;
                    })}
                    testID="apple"
                />
            ) : null}
        </>
    );
}

/** "Resend the email" with a 60 second wait between sends */
export function ResendVerification({ email, startLeft = 0 }: { email: string; /** Seconds to wait first (an email just went out) */ startLeft?: number }) {
    const c = useTheme();
    const [left, setLeft] = useState(startLeft);
    const [sending, setSending] = useState(false);
    const [note, setNote] = useState<{ text: string; ok: boolean } | null>(null);

    useEffect(() => {
        if (left <= 0) return;

        const timer = setTimeout(() => setLeft((value) => value - 1), 1000);

        return () => clearTimeout(timer);
    }, [left]);

    const resend = async () => {
        setSending(true);
        setNote(null);
        const result = await api.resendVerification(email);
        setSending(false);

        if (result.kind === 'ok') {
            setNote({ text: 'We sent a new link. It can take a minute to arrive.', ok: true });
            setLeft(RESEND_SECONDS);
        } else {
            setNote({ text: problemText(result), ok: false });
        }
    };

    return (
        <View style={{ gap: 8 }}>
            <Button
                kind="secondary"
                icon={Mail}
                label={left > 0 ? `Send again in ${left} s` : 'Send the email again'}
                accessibilityLabel={left > 0 ? `Send the email again, available in ${left} seconds` : 'Send the email again'}
                loading={sending}
                disabled={left > 0}
                onPress={resend}
                testID="resend"
            />
            {note?.ok ? (
                <View accessibilityLiveRegion="polite" style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                    <Check size={16} color={c.tone.green.fg} />
                    <Text v="small" style={{ flex: 1 }}>
                        {note.text}
                    </Text>
                </View>
            ) : (
                <FormError>{note?.text}</FormError>
            )}
        </View>
    );
}
