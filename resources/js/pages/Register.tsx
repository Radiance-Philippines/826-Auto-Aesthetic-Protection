import { type FormEvent, useRef, useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';

import { AuthFoot, AuthPage, ErrorSummary, OrDivider, PasswordField, type Problem, problemsOf } from '../components/AuthParts';
import { buttonClass } from '../components/Button';
import { Notice, TextField } from '../components/Form';
import GoogleButton from '../components/GoogleButton';
import { User } from '../components/Icons';
import { accountPagePath, emailSignIn, guest, postAuth, passwordProblem, safeReturn, signInHref } from '../lib/auth';
import { copy } from '../lib/copy';
import { emailProblem, optionalPhoneProblem } from '../lib/customerAuth';
import { limitPhone } from '../lib/draft';
import { site } from '../lib/site';
import usePageTitle from '../lib/usePageTitle';

const text = copy.register;
const auth = copy.auth;
const ids = { name: 'register-name', email: 'register-email', password: 'register-password', phone: 'register-phone' };

/**
 * Create an account with an email and a password (or with Google). The
 * email is confirmed by a link before the account can sign in; the demo
 * verifies it at once. Never says whether an email already has an account.
 */
export default function Register() {
    usePageTitle(text.title);
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const back = safeReturn(params.get('return'));
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [phone, setPhone] = useState('');
    const [busy, setBusy] = useState(false);
    const [problems, setProblems] = useState<Problem[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const summary = useRef<HTMLDivElement>(null);

    if (guest) {
        return <Navigate to={back ?? '/account'} replace />;
    }

    const fail = (list: Problem[], byField: Record<string, string>) => {
        setProblems(list);
        setFieldErrors(byField);
        requestAnimationFrame(() => summary.current?.focus());
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const byField: Record<string, string> = {};
        const checks: [string, string | null][] = [
            [ids.name, name.trim().length < 2 ? auth.nameRequired : null],
            [ids.email, emailProblem(email)],
            [ids.password, passwordProblem(password, email)],
            [ids.phone, optionalPhoneProblem(phone)],
        ];

        for (const [field, message] of checks) {
            if (message) {
                byField[field] = message;
            }
        }

        if (Object.keys(byField).length > 0) {
            fail(Object.entries(byField).map(([field, message]) => ({ field, message })), byField);
            return;
        }

        setBusy(true);
        const reply = await postAuth<{ status?: string }>('register', { name: name.trim(), email: email.trim(), password, phone: phone.trim() || null });

        if (reply.status === 202) {
            navigate('/verify-email', { state: { email: email.trim().toLowerCase(), back } });
            return;
        }

        if (reply.status === 201) {
            window.location.assign(site.base + (back ?? '/account'));
            return;
        }

        setBusy(false);
        const sorted = problemsOf(reply, { name: ids.name, email: ids.email, password: ids.password, phone: ids.phone });
        fail(sorted.problems, sorted.byField);
    };

    return (
        <AuthPage eyebrow={text.eyebrow} title={text.title} intro={text.intro}>
            {emailSignIn ? (
                <form onSubmit={submit} noValidate className="mt-5">
                    <ErrorSummary problems={problems} ref={summary} />
                    {site.auth.emailAutoVerify && (
                        <Notice role="note" className="mb-5">
                            {auth.demoNote}
                        </Notice>
                    )}
                    <div className="grid gap-5">
                        <TextField id={ids.name} name="name" label={auth.name} value={name} onChange={setName} error={fieldErrors[ids.name]} autoComplete="name" maxLength={80} enterKeyHint="next" required />
                        <TextField
                            id={ids.email}
                            name="email"
                            label={auth.email}
                            hint={site.auth.emailAutoVerify ? undefined : auth.emailHint}
                            value={email}
                            onChange={setEmail}
                            error={fieldErrors[ids.email]}
                            type="email"
                            inputMode="email"
                            autoComplete="username"
                            autoCapitalize="none"
                            autoCorrect="off"
                            spellCheck={false}
                            maxLength={190}
                            enterKeyHint="next"
                            required
                        />
                        <PasswordField id={ids.password} label={auth.password} hint={auth.passwordHint} value={password} onChange={setPassword} error={fieldErrors[ids.password]} autoComplete="new-password" />
                        <TextField id={ids.phone} name="phone" label={auth.phone} hint={auth.phoneHint} value={phone} onChange={(value) => setPhone(limitPhone(value))} error={fieldErrors[ids.phone]} type="tel" inputMode="tel" autoComplete="tel" maxLength={40} enterKeyHint="done" optional />
                    </div>
                    <button type="submit" disabled={busy} className={buttonClass('accent', 'mt-6 w-full sm:w-auto sm:min-w-[14rem]')}>
                        <User className="size-5" /> {busy ? text.submitting : text.submit}
                    </button>
                </form>
            ) : (
                <p className="mt-3 leading-relaxed text-muted">{text.unavailable}</p>
            )}

            {site.auth.googleEnabled && (
                <>
                    {emailSignIn && <OrDivider />}
                    <GoogleButton href={signInHref(back ?? '/account')} label={copy.account.googleCreate} className={`${emailSignIn ? '' : 'mt-5 '}w-full sm:w-auto sm:min-w-[18rem]`} />
                </>
            )}

            <AuthFoot>
                {text.haveOne}
                <Link to={accountPagePath('login', back)} className="press inline-flex min-h-11 items-center font-semibold text-text underline underline-offset-4">
                    {text.signIn}
                </Link>
            </AuthFoot>
        </AuthPage>
    );
}
