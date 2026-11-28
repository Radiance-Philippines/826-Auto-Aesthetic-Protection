import { Checkbox, CheckboxIndicator } from '@ui-sdk/react/checkbox';
import { OTPInput, OTPInputGroup, OTPInputSlot } from '@ui-sdk/react/otp-input';
import { type FormEvent, useEffect, useRef, useState } from 'react';

import { ApiError, errorMessage, fieldErrors, request } from '../lib/api';
import { type AuthPage, boot, type TwoFactorStep } from '../lib/boot';
import { SecretField } from '../shell/SecretField';
import { Button } from '../ui/Button';
import { FieldShell, TextField } from '../ui/Field';
import { Icon } from '../ui/icons';
import { Heading, linkClass, Notice } from './Layout';
import { conditionalSupported, explainPasskeyError, passkeysSupported, signInWithPasskey } from './passkey';

const url = (path: string) => `${boot.base}/console/login${path}`;

/** Leaving for the console: the form stays busy until the next page is there */
const go = (to: string) => window.location.assign(to);

type Page = Extract<AuthPage, { screen: 'sign-in' }>;

export default function SignIn({ page }: { page: Page }) {
    const [step, setStep] = useState<TwoFactorStep | null>(page.twoFactor);
    const [notice, setNotice] = useState<string | null>(null);
    // Back from the code step: the keyboard goes to the first field, not nowhere
    const [returned, setReturned] = useState(false);

    const restart = (message: string | null) => {
        setStep(null);
        setNotice(message);
        setReturned(true);
    };

    return step ? <CodeStep step={step} onRestart={restart} /> : <PasswordStep page={page} notice={notice} returned={returned} onCodeNeeded={setStep} />;
}

function PasswordStep({ page, notice, returned, onCodeNeeded }: { page: Page; notice: string | null; returned: boolean; onCodeNeeded: (step: TwoFactorStep) => void }) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(notice);
    const [busy, setBusy] = useState(false);
    const [passkeyBusy, setPasskeyBusy] = useState(false);
    const [passkeyMessage, setPasskeyMessage] = useState<string | null>(null);
    const autofill = useRef<AbortController | null>(null);
    const rememberNow = useRef(remember);
    const passkeys = page.passkeys && passkeysSupported();

    rememberNow.current = remember;

    // Passkeys offered in the email field's autofill list
    const offerInAutofill = async () => {
        autofill.current?.abort();
        const controller = (autofill.current = new AbortController());

        try {
            if ((await conditionalSupported()) && !controller.signal.aborted) {
                go(await signInWithPasskey(() => rememberNow.current, 'conditional', controller.signal));
            }
        } catch (error) {
            if (!(error instanceof DOMException) && !controller.signal.aborted) {
                setPasskeyMessage(errorMessage(error));
            }
        }
    };

    useEffect(() => {
        if (passkeys) {
            void offerInAutofill();
        }

        return () => autofill.current?.abort();
    }, []);

    const usePasskey = async () => {
        setPasskeyMessage(null);
        setPasskeyBusy(true);
        autofill.current?.abort();

        try {
            go(await signInWithPasskey(() => rememberNow.current));
        } catch (error) {
            setPasskeyMessage(explainPasskeyError(error));
            setPasskeyBusy(false);
            void offerInAutofill();
        }
    };

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setErrors({});
        setFailed(null);
        setBusy(true);

        try {
            const answer = await request<{ redirect?: string; twoFactor?: TwoFactorStep }>('POST', url(''), { email, password, remember });

            if (answer.twoFactor) {
                autofill.current?.abort();
                onCodeNeeded(answer.twoFactor);

                return;
            }

            go(answer.redirect!);
        } catch (error) {
            const fields = fieldErrors(error);
            setErrors(fields);
            setFailed(Object.keys(fields).length ? null : errorMessage(error));
            setBusy(false);
        }
    };

    return (
        <>
            <Heading title="Sign in">
                Use your team account.
            </Heading>
            {page.status && !notice && <Notice tone="success">{page.status}</Notice>}
            {failed && <Notice tone="error">{failed}</Notice>}
            <form onSubmit={submit} className="space-y-4">
                <TextField
                    label="Email"
                    hint="The address your invite was sent to."
                    error={errors.email}
                    type="email"
                    name="email"
                    value={email}
                    onChange={(event) => {
                        setEmail(event.target.value);
                        setErrors({});
                    }}
                    autoComplete={page.passkeys ? 'username webauthn' : 'username'}
                    autoFocus={returned || window.matchMedia('(pointer: fine)').matches}
                    spellCheck={false}
                    enterKeyHint="next"
                    required
                />
                <div>
                    <SecretField label="Password" hint="Capital letters count." error={errors.password} name="password" value={password} onChange={setPassword} autoComplete="current-password" required />
                    <a href={`${boot.base}/console/forgot-password`} className={`${linkClass} mt-1.5`}>
                        <Icon name="key-round" />
                        Forgot password?
                    </a>
                </div>
                <div>
                    <label className="flex cursor-pointer items-center gap-2 text-sm font-medium pointer-coarse:min-h-11">
                        <Checkbox checked={remember} onCheckedChange={(next) => setRemember(next === true)} aria-describedby="remember-hint">
                            <CheckboxIndicator>
                                <Icon name="check" className="size-3.5" />
                            </CheckboxIndicator>
                        </Checkbox>
                        Remember me
                    </label>
                    <p id="remember-hint" className="text-xs text-muted-foreground">
                        Stay signed in on this device. Leave it off on a shared one.
                    </p>
                </div>
                <Button type="submit" icon="log-in" variant="primary" busy={busy} className="h-11 w-full">
                    Sign in
                </Button>
            </form>
            {passkeys && (
                <div className="mt-5 grid gap-3">
                    <div className="flex items-center gap-3 text-xs text-muted-foreground" aria-hidden="true">
                        <span className="h-px flex-1 bg-border" />
                        or
                        <span className="h-px flex-1 bg-border" />
                    </div>
                    <Button icon="fingerprint" busy={passkeyBusy} onClick={() => void usePasskey()} className="h-11 w-full">
                        Sign in with a passkey
                    </Button>
                    {passkeyMessage && <Notice tone="error">{passkeyMessage}</Notice>}
                </div>
            )}
        </>
    );
}

type Way = 'app' | 'recovery' | 'email';

const emailNote = (sent: boolean | null) => (sent === false ? 'A code went out a moment ago. Check your email, or wait a minute before asking again.' : null);

function CodeStep({ step, onRestart }: { step: TwoFactorStep; onRestart: (message: string | null) => void }) {
    const [way, setWay] = useState<Way>(step.methods[0]);
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [sentNote, setSentNote] = useState<string | null>(emailNote(step.sent));
    const [busy, setBusy] = useState(false);
    const [sending, setSending] = useState(false);
    // The password step already sent one when email is the first way offered
    const emailed = useRef(step.methods[0] === 'email' && step.sent !== null);

    const handle = (thrown: unknown) => {
        if (thrown instanceof ApiError && thrown.errors.restart) {
            onRestart(thrown.errors.restart[0]);

            return;
        }

        const fields = fieldErrors(thrown);
        setError(fields.code ?? null);
        setFailed(fields.code ? null : errorMessage(thrown));
    };

    const send = async () => {
        setSending(true);
        setFailed(null);
        setSentNote(null);

        try {
            const { sent } = await request<{ sent: boolean }>('POST', url('/two-factor/email'));
            emailed.current = true;
            setSentNote(emailNote(sent) ?? 'A new code is on its way.');
        } catch (thrown) {
            handle(thrown);
        } finally {
            setSending(false);
        }
    };

    const choose = (next: Way) => {
        setWay(next);
        setCode('');
        setError(null);
        setFailed(null);

        if (next === 'email' && !emailed.current) {
            void send();
        }
    };

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setFailed(null);
        setBusy(true);

        try {
            const { redirect } = await request<{ redirect: string }>('POST', url('/two-factor'), { method: way === 'email' ? 'email' : 'app', code });
            go(redirect);
        } catch (thrown) {
            handle(thrown);
            setBusy(false);
        }
    };

    const back = async () => {
        await request('DELETE', url('/two-factor')).catch(() => {});
        onRestart(null);
    };

    return (
        <>
            <Heading title="Two-factor sign-in">
                {way === 'app' && 'Enter the code your authenticator app shows now.'}
                {way === 'recovery' && 'Enter one of the recovery codes you saved. Each works once.'}
                {way === 'email' && 'Enter the code we emailed you. It works for a few minutes.'}
            </Heading>
            {failed && <Notice tone="error">{failed}</Notice>}
            {way === 'email' && sentNote && <Notice tone="success">{sentNote}</Notice>}
            <form onSubmit={submit} className="space-y-4">
                {way === 'recovery' ? (
                    <TextField
                        key={way}
                        label="Recovery code"
                        hint="As you saved it, with its dash."
                        error={error}
                        value={code}
                        onChange={(event) => {
                            setCode(event.target.value);
                            setError(null);
                        }}
                        autoComplete="off"
                        autoCapitalize="off"
                        spellCheck={false}
                        autoFocus
                        required
                    />
                ) : (
                    <FieldShell key={way} label="Code" hint="6 digits. Pasting works." error={error}>
                        <OTPInput
                            maxLength={6}
                            value={code}
                            onValueChange={(next) => {
                                setCode(String(next));
                                setError(null);
                            }}
                            autoFocus
                            required
                        >
                            <OTPInputGroup className="auth-otp">
                                {[0, 1, 2, 3, 4, 5].map((index) => (
                                    <OTPInputSlot key={index} index={index} />
                                ))}
                            </OTPInputGroup>
                        </OTPInput>
                    </FieldShell>
                )}
                <Button type="submit" icon="shield-check" variant="primary" busy={busy} className="h-11 w-full">
                    Verify and sign in
                </Button>
            </form>
            <div className="mt-4 flex flex-col items-stretch gap-2">
                {way === 'email' && (
                    <Button icon="send" busy={sending} onClick={() => void send()}>
                        Send a new code
                    </Button>
                )}
                {step.methods.includes('app') && way !== 'app' && (
                    <Button icon="smartphone" variant="ghost" onClick={() => choose('app')}>
                        Use the authenticator app
                    </Button>
                )}
                {step.methods.includes('app') && way !== 'recovery' && (
                    <Button icon="key-round" variant="ghost" onClick={() => choose('recovery')}>
                        Use a recovery code
                    </Button>
                )}
                {step.methods.includes('email') && way !== 'email' && (
                    <Button icon="at-sign" variant="ghost" onClick={() => choose('email')}>
                        Email me a code instead
                    </Button>
                )}
                <Button icon="arrow-left" variant="ghost" onClick={() => void back()}>
                    Back to sign in
                </Button>
            </div>
        </>
    );
}
