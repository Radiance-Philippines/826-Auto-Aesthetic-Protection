import { type FormEvent, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';

import { CardBone, Loading, Problem } from '../../components/AccountParts';
import Avatar from '../../components/Avatar';
import { buttonClass } from '../../components/Button';
import { PasswordField } from '../../components/AuthParts';
import { ErrorText, Notice, TextField } from '../../components/Form';
import { Check, SignOut, Trash } from '../../components/Icons';
import Modal from '../../components/Modal';
import { fetchProfile, useLoad } from '../../lib/account';
import { type FieldErrors, patchJson, postJson } from '../../lib/api';
import { afterSignOut, guest, passwordProblem, signOut } from '../../lib/auth';
import { copy } from '../../lib/copy';
import { limitPhone, phoneProblem } from '../../lib/draft';
import { site } from '../../lib/site';
import type { MyProfile } from '../../types';

const text = copy.me.profile;

function Details({ profile }: { profile: MyProfile }) {
    const [name, setName] = useState(profile.name);
    const [phone, setPhone] = useState(profile.phone ?? '');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [state, setState] = useState<'idle' | 'saving' | 'saved' | 'failed'>('idle');

    const save = async (event: FormEvent) => {
        event.preventDefault();
        const problems: FieldErrors = {};

        if (!name.trim()) {
            problems.name = [copy.book.errors.name];
        }

        // The phone is optional here; when typed it has to look like a number
        if (phone.trim() && phoneProblem(phone)) {
            problems.phone = [copy.book.errors.phoneFormat];
        }

        setErrors(problems);

        if (Object.keys(problems).length > 0) {
            requestAnimationFrame(() => document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
            return;
        }

        setState('saving');
        const result = await patchJson<MyProfile>('/me', { name: name.trim(), phone: phone.trim() || null });

        if (result.kind === 'ok') {
            // The header and the greeting use the name from page load
            if (guest) {
                guest.name = result.data.name;
            }

            setName(result.data.name);
            setPhone(result.data.phone ?? '');
            setState('saved');
        } else if (result.kind === 'invalid') {
            setErrors(result.errors);
            setState('idle');
        } else {
            setState('failed');
        }
    };

    return (
        <form onSubmit={save} noValidate className="panel p-5 sm:p-6">
            <div className="flex items-center gap-4">
                {guest && <Avatar guest={guest} className="size-14 text-lg" />}
                <div className="min-w-0">
                    <p className="text-sm text-muted">{text.email}</p>
                    <p className="font-semibold break-all">{profile.email ?? '—'}</p>
                </div>
            </div>
            {profile.signInMethod && (
                <p className="mt-3 flex items-center gap-2 text-sm font-medium">
                    <Check className="size-4 text-accent" /> {copy.auth.method[profile.signInMethod]}
                </p>
            )}
            {profile.email && profile.signInMethod !== 'password' && <p className="mt-1 text-sm text-muted">{text.emailNote}</p>}
            {profile.email && profile.signInMethod === 'password' && <p className="mt-1 text-sm text-muted">{text.emailNotePassword}</p>}

            <div className="mt-6 grid gap-5">
                <TextField label={text.name} value={name} onChange={setName} error={errors.name?.[0]} autoComplete="name" name="name" maxLength={120} required />
                <TextField label={text.phone} hint={text.phoneHint} value={phone} onChange={(value) => setPhone(limitPhone(value))} error={errors.phone?.[0]} type="tel" inputMode="tel" autoComplete="tel" name="phone" maxLength={40} />
            </div>

            <div className="mt-6 flex flex-wrap items-center gap-3">
                <button type="submit" disabled={state === 'saving'} className={buttonClass('accent')}>
                    <Check className="size-4" /> {state === 'saving' ? text.saving : text.save}
                </button>
                <span role="status" className="text-sm font-medium text-muted">
                    {state === 'saved' ? (site.demo ? text.demo : text.saved) : ''}
                </span>
            </div>
            {state === 'failed' && <ErrorText>{text.failed}</ErrorText>}
        </form>
    );
}

/** Only for an account made with an email and password: the current password first */
function ChangePassword({ email }: { email: string | null }) {
    const pw = text.password;
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [state, setState] = useState<'idle' | 'saving' | 'saved' | 'failed'>('idle');

    const save = async (event: FormEvent) => {
        event.preventDefault();
        const problems: FieldErrors = {};

        if (!current) {
            problems.current_password = [copy.auth.passwordRequired];
        }

        const problem = passwordProblem(next, email ?? '');

        if (problem) {
            problems.password = [problem];
        }

        setErrors(problems);

        if (Object.keys(problems).length > 0) {
            requestAnimationFrame(() => document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
            return;
        }

        setState('saving');
        const result = await patchJson<MyProfile>('/me', { current_password: current, password: next });

        if (result.kind === 'ok') {
            setCurrent('');
            setNext('');
            setState('saved');
        } else if (result.kind === 'invalid') {
            setErrors(result.errors);
            setState('idle');
        } else {
            setState('failed');
        }
    };

    return (
        <form onSubmit={save} noValidate className="panel mt-6 p-5 sm:p-6">
            <h3 className="text-lg font-bold">{pw.title}</h3>
            {email && <input type="email" name="email" value={email} autoComplete="username" readOnly hidden />}
            <div className="mt-5 grid gap-5">
                <PasswordField id="me-current-password" name="current_password" label={copy.auth.currentPassword} value={current} onChange={setCurrent} error={errors.current_password?.[0]} autoComplete="current-password" />
                <PasswordField id="me-new-password" name="new_password" label={copy.auth.newPassword} hint={copy.auth.passwordHint} value={next} onChange={setNext} error={errors.password?.[0]} autoComplete="new-password" />
            </div>
            <div className="mt-6 flex flex-wrap items-center gap-3">
                <button type="submit" disabled={state === 'saving'} className={buttonClass('accent')}>
                    <Check className="size-4" /> {state === 'saving' ? pw.saving : pw.save}
                </button>
                <span role="status" className="text-sm font-medium text-muted">
                    {state === 'saved' ? (site.demo ? pw.demo : pw.saved) : ''}
                </span>
            </div>
            {state === 'failed' && <ErrorText>{text.failed}</ErrorText>}
        </form>
    );
}

function DeleteAccount() {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [demoDone, setDemoDone] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const keep = useRef<HTMLButtonElement>(null);
    const { pathname } = useLocation();

    const confirm = async () => {
        setBusy(true);
        setMessage(null);
        const result = await postJson<{ deleted: boolean; demo?: boolean }>('/me/delete', {});
        setBusy(false);

        if (result.kind === 'ok' && result.data.demo) {
            setOpen(false);
            setDemoDone(true);
        } else if (result.kind === 'ok') {
            await signOut();
            afterSignOut(pathname);
        } else {
            setMessage(result.kind === 'conflict' && result.message ? result.message : text.delete.failed);
        }
    };

    return (
        <>
            <button ref={trigger} type="button" onClick={() => setOpen(true)} className={buttonClass('outline', 'text-danger')}>
                <Trash className="size-4" /> {text.delete.open}
            </button>
            {demoDone && (
                <p role="status" className="mt-3 text-sm text-muted">
                    {text.demo}
                </p>
            )}
            {open && (
                <Modal title={text.delete.title} description={text.delete.body} onClose={() => !busy && setOpen(false)} initialFocus={keep} className="sm:max-w-lg">
                    {message && (
                        <Notice tone="danger" role="alert" className="mb-4">
                            {message}
                        </Notice>
                    )}
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <button ref={keep} type="button" onClick={() => setOpen(false)} disabled={busy} className={buttonClass('accent')}>
                            <Check className="size-4" /> {text.delete.keep}
                        </button>
                        <button type="button" onClick={confirm} disabled={busy} className={buttonClass('outline', 'text-danger')}>
                            <Trash className="size-4" /> {busy ? text.delete.deleting : text.delete.confirm}
                        </button>
                    </div>
                </Modal>
            )}
        </>
    );
}

export default function Me() {
    const { load, reload } = useLoad(fetchProfile);
    const [leaving, setLeaving] = useState(false);
    const { pathname } = useLocation();

    const leave = async () => {
        setLeaving(true);
        await signOut();
        afterSignOut(pathname);
    };

    return (
        <>
            <h2 className="font-display text-[clamp(1.6rem,5vw,2.4rem)]">{text.title}</h2>
            <p className="mt-3 text-lg text-muted">{text.intro}</p>

            <div className="mt-8">
                {load.state === 'loading' && (
                    <Loading>
                        <CardBone className="h-24" />
                    </Loading>
                )}
                {(load.state === 'failed' || load.state === 'signedOut' || load.state === 'missing') && <Problem kind={load.state === 'signedOut' ? 'signedOut' : 'failed'} onRetry={reload} />}
                {load.state === 'ok' && <Details profile={load.data} />}
                {load.state === 'ok' && load.data.signInMethod === 'password' && <ChangePassword email={load.data.email} />}

                <div className="mt-10 flex flex-wrap items-center gap-3">
                    <button type="button" onClick={leave} disabled={leaving} className={buttonClass('outline')}>
                        <SignOut className="size-4" /> {leaving ? copy.account.signingOut : text.signOut}
                    </button>
                    <DeleteAccount />
                </div>
            </div>
        </>
    );
}
