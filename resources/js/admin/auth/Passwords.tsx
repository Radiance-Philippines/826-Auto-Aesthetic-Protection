import { type FormEvent, useState } from 'react';

import { errorMessage, fieldErrors, request } from '../lib/api';
import { boot } from '../lib/boot';
import { SecretField } from '../shell/SecretField';
import { Button, buttonClass } from '../ui/Button';
import { TextField } from '../ui/Field';
import { Icon } from '../ui/icons';
import { backToSignIn, Heading, Notice } from './Layout';

const url = (path: string) => `${boot.base}/console/${path}`;

export function ForgotPassword() {
    const [email, setEmail] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(null);
    const [sent, setSent] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setErrors({});
        setFailed(null);
        setBusy(true);

        try {
            setSent((await request<{ message: string }>('POST', url('forgot-password'), { email })).message);
        } catch (error) {
            const fields = fieldErrors(error);
            setErrors(fields);
            setFailed(Object.keys(fields).length ? null : errorMessage(error));
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Heading title="Forgot your password?">We’ll email you a link to choose a new one.</Heading>
            {failed && <Notice tone="error">{failed}</Notice>}
            {sent ? (
                <Notice tone="success">{sent}</Notice>
            ) : (
                <form onSubmit={submit} className="mb-4 space-y-4">
                    <TextField
                        label="Email"
                        hint="The address you sign in with."
                        error={errors.email}
                        type="email"
                        name="email"
                        value={email}
                        onChange={(event) => {
                            setEmail(event.target.value);
                            setErrors({});
                        }}
                        autoComplete="username"
                        autoFocus={window.matchMedia('(pointer: fine)').matches}
                        spellCheck={false}
                        enterKeyHint="send"
                        required
                    />
                    <Button type="submit" icon="send" variant="primary" busy={busy} className="h-11 w-full">
                        Email me the link
                    </Button>
                </form>
            )}
            {backToSignIn}
        </>
    );
}

export function ResetPassword({ email, token }: { email: string; token: string }) {
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(null);
    // Without both halves of the link there is nothing to send
    const [dead, setDead] = useState<string | null>(email && token ? null : 'This link is incomplete. Open the whole link from your email, or ask for a new one.');
    const [busy, setBusy] = useState(false);

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFailed(null);

        // Checked here too: each try that reaches the server counts against 2 a minute
        if (password !== confirmation) {
            setErrors({ password_confirmation: 'The two passwords don’t match.' });

            return;
        }

        setErrors({});
        setBusy(true);

        try {
            const { redirect } = await request<{ redirect: string }>('POST', url('reset-password'), { token, email, password, password_confirmation: confirmation });
            window.location.assign(redirect);
        } catch (error) {
            const fields = fieldErrors(error);

            if (fields.token || fields.email) {
                setDead(fields.token ?? fields.email);
            } else {
                setErrors(fields);
                setFailed(Object.keys(fields).length ? null : errorMessage(error));
            }

            setBusy(false);
        }
    };

    if (dead) {
        return (
            <>
                <Heading title="This link can’t be used" />
                <Notice tone="error">{dead}</Notice>
                <a href={url('forgot-password')} className={`${buttonClass('primary')} mb-4 h-11 w-full`}>
                    <Icon name="send" />
                    Ask for a new link
                </a>
                {backToSignIn}
            </>
        );
    }

    return (
        <>
            <Heading title="Choose a new password">
                For <strong className="font-semibold break-all text-foreground">{email}</strong>
            </Heading>
            {failed && <Notice tone="error">{failed}</Notice>}
            <form onSubmit={submit} className="mb-4 space-y-4">
                {/* For password managers: which account the new password belongs to */}
                <input type="email" name="email" value={email} autoComplete="username" readOnly hidden />
                <SecretField
                    required
                    label="New password"
                    hint="At least 8 characters."
                    error={errors.password}
                    name="password"
                    value={password}
                    onChange={(next) => {
                        setPassword(next);
                        setErrors({});
                    }}
                    autoComplete="new-password"
                    minLength={8}
                />
                <SecretField
                    required
                    label="Confirm new password"
                    hint="The same password again."
                    error={errors.password_confirmation}
                    name="password_confirmation"
                    value={confirmation}
                    onChange={(next) => {
                        setConfirmation(next);
                        setErrors({});
                    }}
                    autoComplete="new-password"
                />
                <Button type="submit" icon="save" variant="primary" busy={busy} className="h-11 w-full">
                    Save new password
                </Button>
            </form>
            {backToSignIn}
        </>
    );
}
