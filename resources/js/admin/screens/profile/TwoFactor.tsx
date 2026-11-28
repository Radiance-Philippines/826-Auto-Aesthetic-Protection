import { OTPInput, OTPInputGroup, OTPInputSlot } from '@ui-sdk/react/otp-input';
import { type FormEvent, useEffect, useState } from 'react';

import { api, errorMessage } from '../../lib/api';
import { boot } from '../../lib/boot';
import { SecretField } from '../../shell/SecretField';
import { Button, buttonClass } from '../../ui/Button';
import { Modal, ModalActions } from '../../ui/Dialog';
import { FieldShell, TextField } from '../../ui/Field';
import { Icon } from '../../ui/icons';
import { Badge, Loading } from '../../ui/states';
import { toast } from '../../ui/toast';
import { Alert, copy, Section, useSubmit } from '../bookings/parts';
import type { Profile } from './types';

type Kind = 'app-on' | 'app-off' | 'codes' | 'email-on' | 'email-off';

type Setup = { secret: string; qr: string };

/**
 * What is open, with the request its opening click made. Made there, not when
 * the popup mounts: it can mount twice (dialog, then sheet on a phone), which
 * would send two emails or make two secrets.
 */
type Task = { kind: 'app-off' | 'codes' } | { kind: 'app-on'; setup: Promise<Setup> } | { kind: 'email-on' | 'email-off'; sent: Promise<string> };

const askForSetup = () => api<Setup>('POST', '/profile/two-factor/app');

/** Sends a code and says how that went */
const sendCode = () =>
    api<{ sent: boolean }>('POST', '/profile/two-factor/email/code').then(
        ({ sent }) => (sent ? 'We emailed you a code.' : 'A code went out a moment ago. Check your email, or wait a minute before asking again.'),
        (error: unknown) => errorMessage(error),
    );

const titles: Record<Kind, string> = {
    'app-on': 'Set up an authenticator app',
    'app-off': 'Turn off the authenticator app',
    codes: 'New recovery codes',
    'email-on': 'Set up emailed codes',
    'email-off': 'Turn off emailed codes',
};

type Done = (next: Profile, message: string, codes?: string[]) => void;

/** The authenticator app (with recovery codes) and the emailed code */
export function TwoFactor({ profile, forced, onSaved }: { profile: Profile; forced: boolean; onSaved: (next: Profile) => void }) {
    const [task, setTask] = useState<Task | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const { app, email, recoveryCodes } = profile.twoFactor;

    const done: Done = (next, message, fresh) => {
        setTask(null);
        setCodes(fresh ?? null);
        onSaved(next);
        toast.success(message);
    };

    const row = 'flex flex-wrap items-center justify-between gap-3 py-3';

    return (
        <Section title="Two-factor sign-in">
            {profile.setupRequired && (
                <div className="mb-3">
                    <Alert tone="warning" title="Set up two-factor sign-in to continue">
                        It is required for the whole team. Choose one of these.
                    </Alert>
                </div>
            )}
            {codes && <RecoveryCodes codes={codes} onDone={() => setCodes(null)} />}
            <ul className="m-0 list-none divide-y divide-border p-0">
                <li className={row}>
                    <div className="min-w-0 flex-1 basis-56">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            Authenticator app <Badge tone={app ? 'selected' : 'neutral'}>{app ? 'On' : 'Off'}</Badge>
                        </p>
                        <p className="text-xs text-muted-foreground">{app ? `A code from your phone at each sign-in. ${recoveryCodes} recovery codes left.` : 'A code from your phone at each sign-in.'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {app ? (
                            <>
                                <Button icon="refresh-cw" size="sm" onClick={() => setTask({ kind: 'codes' })}>
                                    New recovery codes
                                </Button>
                                <Button icon="lock-open" size="sm" variant="destructive" onClick={() => setTask({ kind: 'app-off' })} aria-label="Turn off the authenticator app">
                                    Turn off
                                </Button>
                            </>
                        ) : (
                            <Button icon="smartphone" size="sm" variant="primary" onClick={() => setTask({ kind: 'app-on', setup: askForSetup() })} aria-label="Set up an authenticator app">
                                Set up
                            </Button>
                        )}
                    </div>
                </li>
                <li className={row}>
                    <div className="min-w-0 flex-1 basis-56">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            Emailed code <Badge tone={email ? 'selected' : 'neutral'}>{email ? 'On' : 'Off'}</Badge>
                        </p>
                        <p className="text-xs text-muted-foreground">A code sent to {profile.email} at each sign-in.</p>
                    </div>
                    {email ? (
                        <Button icon="lock-open" size="sm" variant="destructive" onClick={() => setTask({ kind: 'email-off', sent: sendCode() })} aria-label="Turn off emailed codes">
                            Turn off
                        </Button>
                    ) : (
                        <Button icon="at-sign" size="sm" onClick={() => setTask({ kind: 'email-on', sent: sendCode() })} aria-label="Set up emailed codes">
                            Set up
                        </Button>
                    )}
                </li>
            </ul>
            {forced && !profile.setupRequired && (
                <a href={`${boot.base}/console`} className={`${buttonClass('primary')} mt-3`}>
                    <Icon name="arrow-right-to-line" />
                    Continue to the admin
                </a>
            )}
            <Modal open={task !== null} onClose={() => setTask(null)} title={task ? titles[task.kind] : ''}>
                {task?.kind === 'app-on' && <AppOn setup={task.setup} onDone={done} onCancel={() => setTask(null)} />}
                {(task?.kind === 'app-off' || task?.kind === 'codes') && <WithPassword task={task.kind} onDone={done} onCancel={() => setTask(null)} />}
                {(task?.kind === 'email-on' || task?.kind === 'email-off') && <EmailCode on={task.kind === 'email-on'} sent={task.sent} onDone={done} onCancel={() => setTask(null)} />}
            </Modal>
        </Section>
    );
}

function Code({ value, onChange, error }: { value: string; onChange: (value: string) => void; error?: string }) {
    return (
        <FieldShell label="Code" hint="6 digits. Pasting works." error={error}>
            <OTPInput maxLength={6} value={value} onValueChange={(next) => onChange(String(next))} required>
                <OTPInputGroup className="auth-otp">
                    {[0, 1, 2, 3, 4, 5].map((index) => (
                        <OTPInputSlot key={index} index={index} />
                    ))}
                </OTPInputGroup>
            </OTPInput>
        </FieldShell>
    );
}

function AppOn({ setup: asked, onDone, onCancel }: { setup: Promise<Setup>; onDone: Done; onCancel: () => void }) {
    const [setup, setSetup] = useState<Setup | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const { errors, failed, busy, submit } = useSubmit();

    useEffect(() => {
        asked.then(setSetup, (error: unknown) => setLoadError(errorMessage(error)));
    }, [asked]);

    const confirm = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const { recoveryCodes, ...profile } = await api<Profile & { recoveryCodes: string[] }>('POST', '/profile/two-factor/app/confirm', { code, current_password: password });
            onDone(profile, 'Authenticator app turned on', recoveryCodes);
        });
    };

    if (loadError) {
        return <Alert>{loadError}</Alert>;
    }

    if (!setup) {
        return <Loading rows={3} />;
    }

    return (
        <form onSubmit={confirm} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            <p className="text-sm text-muted-foreground">Scan this with Google Authenticator, Authy or a similar app, then enter the code it shows.</p>
            {/* White behind it in both themes: scanners need the contrast */}
            <img src={setup.qr} alt="QR code for your authenticator app" width={192} height={192} className="mx-auto size-48 rounded-md bg-white p-2" />
            <p className="text-center text-xs text-muted-foreground">
                Can’t scan? Type this key: <code data-testid="totp-secret" className="font-mono break-all text-foreground">{setup.secret}</code>
            </p>
            <Code value={code} onChange={setCode} error={errors.code} />
            <SecretField label="Current password" hint="Confirms it is you." error={errors.current_password} value={password} onChange={setPassword} autoComplete="current-password" />
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="shield-check" variant="primary" busy={busy}>
                    Turn on
                </Button>
            </ModalActions>
        </form>
    );
}

function WithPassword({ task, onDone, onCancel }: { task: 'app-off' | 'codes'; onDone: Done; onCancel: () => void }) {
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const { errors, failed, busy, submit } = useSubmit();
    const off = task === 'app-off';

    const send = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            if (off) {
                onDone(await api<Profile>('DELETE', '/profile/two-factor/app', { current_password: password, code }), 'Authenticator app turned off');
            } else {
                const { recoveryCodes, ...profile } = await api<Profile & { recoveryCodes: string[] }>('POST', '/profile/two-factor/recovery-codes', { current_password: password });
                onDone(profile, 'New recovery codes made', recoveryCodes);
            }
        });
    };

    return (
        <form onSubmit={send} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            {!off && <p className="text-sm text-muted-foreground">The old codes stop working.</p>}
            <SecretField label="Current password" hint="Confirms it is you." error={errors.current_password} value={password} onChange={setPassword} autoComplete="current-password" />
            {off && <TextField label="Code or recovery code" hint="From your authenticator app, or one of your saved codes." error={errors.code} value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" spellCheck={false} />}
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon={off ? 'lock-open' : 'refresh-cw'} variant={off ? 'destructive' : 'primary'} busy={busy}>
                    {off ? 'Turn off' : 'Make new codes'}
                </Button>
            </ModalActions>
        </form>
    );
}

function EmailCode({ on, sent, onDone, onCancel }: { on: boolean; sent: Promise<string>; onDone: Done; onCancel: () => void }) {
    const [code, setCode] = useState('');
    const [note, setNote] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const { errors, failed, busy, submit } = useSubmit();

    useEffect(() => {
        void sent.then(setNote);
    }, [sent]);

    const resend = async () => {
        setSending(true);
        setNote(await sendCode());
        setSending(false);
    };

    const confirm = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => onDone(await api<Profile>(on ? 'POST' : 'DELETE', '/profile/two-factor/email', { code }), on ? 'Emailed codes turned on' : 'Emailed codes turned off'));
    };

    return (
        <form onSubmit={confirm} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            <p role="status" className="text-sm text-muted-foreground">
                {note ?? 'Sending a code…'}
            </p>
            <Code value={code} onChange={setCode} error={errors.code} />
            <Button icon="send" size="sm" variant="ghost" busy={sending} onClick={() => void resend()}>
                Send a new code
            </Button>
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon={on ? 'shield-check' : 'lock-open'} variant={on ? 'primary' : 'destructive'} busy={busy}>
                    {on ? 'Turn on' : 'Turn off'}
                </Button>
            </ModalActions>
        </form>
    );
}

/** Shown once, in the page rather than a popup, so a stray click cannot lose them */
function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
    const text = codes.join('\n');

    return (
        <div role="region" aria-label="Your recovery codes" className="mb-3 space-y-3 rounded-lg border border-border bg-muted p-3">
            <p className="text-sm font-medium">Save these recovery codes now. They are not shown again; each signs you in once without your phone.</p>
            <ul className="m-0 grid list-none gap-1 p-0 font-mono text-sm sm:grid-cols-2">
                {codes.map((code) => (
                    <li key={code} className="break-all">
                        {code}
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-2">
                <Button icon="clipboard-check" size="sm" onClick={() => void copy(text, 'Recovery codes')}>
                    Copy
                </Button>
                <a href={`data:text/plain;charset=utf-8,${encodeURIComponent(text)}`} download="recovery-codes.txt" className={buttonClass('outline', 'sm')}>
                    <Icon name="download" />
                    Download
                </a>
                <Button icon="check" size="sm" variant="primary" onClick={onDone}>
                    I saved them
                </Button>
            </div>
        </div>
    );
}
