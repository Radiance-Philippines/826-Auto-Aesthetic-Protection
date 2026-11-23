import { useRouter } from 'expo-router';
import { ArrowLeft, MailCheck, Send } from 'lucide-react-native';
import { useState } from 'react';
import { api, problemText } from '@/api';
import { useTheme } from '@/context/theme';
import { emailOk } from '@/lib/account';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Field } from '@/ui/Field';
import { Screen } from '@/ui/Screen';
import { FormError } from '@/ui/States';
import { Text } from '@/ui/Text';

export default function ForgotPasswordScreen() {
    const c = useTheme();
    const router = useRouter();
    const [email, setEmail] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(false);

    const submit = async () => {
        if (busy) return;

        if (!emailOk(email)) {
            setError('Enter a valid email address.');

            return;
        }

        setBusy(true);
        setError(null);
        const result = await api.forgotPassword(email.trim());
        setBusy(false);

        if (result.kind === 'ok') setSent(true);
        else if (result.kind === 'invalid') setError(Object.values(result.errors)[0]?.[0] ?? 'Enter a valid email address.');
        else setError(problemText(result));
    };

    return (
        <Screen back="Sign in" title="Forgot password">
            {sent ? (
                <>
                    <Card style={{ gap: 12 }} accessibilityLiveRegion="polite">
                        <MailCheck size={28} color={c.accent} />
                        <Text v="title">Check your email</Text>
                        {/* The same words whether or not the address has an account */}
                        <Text>{`If ${email.trim()} has an account, we emailed you a link. Open it, choose a new password, then come back and sign in.`}</Text>
                        <Text v="small">The link opens in your browser. It works for 60 minutes.</Text>
                    </Card>
                    <Button icon={ArrowLeft} label="Back to sign in" onPress={() => router.replace('/sign-in')} testID="back-to-sign-in" />
                </>
            ) : (
                <>
                    <Text color={c.muted}>Enter the email you signed up with. We’ll email you a link to choose a new password.</Text>
                    <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" autoComplete="email" textContentType="emailAddress" autoCapitalize="none" autoCorrect={false} returnKeyType="send" onSubmitEditing={() => void submit()} error={error} />
                    <Button icon={Send} label="Send link" loading={busy} onPress={() => void submit()} testID="send-link" />
                </>
            )}
        </Screen>
    );
}
