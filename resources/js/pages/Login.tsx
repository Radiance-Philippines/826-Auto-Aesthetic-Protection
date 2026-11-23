import { type FormEvent, useRef, useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';

import { ErrorSummary, OrDivider, PasswordField, type Problem, problemsOf } from '../components/AuthParts';
import { buttonClass } from '../components/Button';
import { TextField, Notice } from '../components/Form';
import GoogleButton from '../components/GoogleButton';
import { PageHeader } from '../components/Heading';
import { ArrowRight, Check, Mail, User, Warning } from '../components/Icons';
import { accountPagePath, canSignIn, emailSignIn, guest, localSignIn, postAuth, safeReturn, signInHref } from '../lib/auth';
import { copy } from '../lib/copy';
import { emailProblem } from '../lib/customerAuth';
import { site } from '../lib/site';
import usePageTitle from '../lib/usePageTitle';

const text = copy.login;
const auth = copy.auth;
const fieldIds = { email: 'login-email', password: 'login-password' };

/**
 * The customer's sign-in page: email and password, with Google (and, on
 * the demo and local copies, the sample customer) as the other ways in.
 * "Create an account" is the next page over. Already signed in: on to
 * `?return=` or the account. The team's /admin login is a separate door,
 * linked small.
 */
export default function Login() {
    usePageTitle(text.title);
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const back = safeReturn(params.get('return'));
    const googleFailed = params.get('signin') === 'failed';
    const notice = params.get('reset') ? text.reset : null;
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [problems, setProblems] = useState<Problem[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [unverified, setUnverified] = useState(false);
    const [resent, setResent] = useState(false);
    const summary = useRef<HTMLDivElement>(null);

    if (guest) {
        return <Navigate to={back ?? '/account'} replace />;
    }

    const href = signInHref(back ?? '/account');

    const fail = (list: Problem[], byField: Record<string, string> = {}) => {
        setProblems(list);
        setFieldErrors(byField);
        requestAnimationFrame(() => summary.current?.focus());
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setUnverified(false);
        setResent(false);

        const byField: Record<string, string> = {};
        const emailError = emailProblem(email);

        if (emailError) {
            byField[fieldIds.email] = emailError;
        }

        if (!password) {
            byField[fieldIds.password] = auth.passwordRequired;
        }

        if (Object.keys(byField).length > 0) {
            fail(Object.entries(byField).map(([field, message]) => ({ field, message })), byField);
            return;
        }

        setBusy(true);
        const reply = await postAuth('login', { email: email.trim(), password });

        if (reply.status === 200) {
            // A full load: the page then knows who is signed in
            window.location.assign(site.base + (back ?? '/account'));
            return;
        }

        setBusy(false);

        if (reply.status === 403 && reply.body?.code === 'email_unverified') {
            setProblems([]);
            setFieldErrors({});
            setUnverified(true);
            return;
        }

        // "Those details don't match" belongs to the pair, not to one box: the summary names it, the email box takes the focus
        const sorted = problemsOf(reply, { email: fieldIds.email, password: fieldIds.password });
        fail(sorted.problems, reply.status === 422 ? {} : sorted.byField);
    };

    const resend = async () => {
        await postAuth('resend-verification', { email: email.trim() });
        setResent(true);
    };

    return (
        <>
            <PageHeader eyebrow={text.eyebrow} title={text.title}>
                <p>{text.intro}</p>
            </PageHeader>
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-8 sm:py-14 xl:px-12">
                <section aria-labelledby="login-title" className="panel max-w-xl p-5 sm:p-7">
                    <h2 id="login-title" className="text-lg font-bold">
                        {emailSignIn ? text.panelTitle : text.title}
                    </h2>

                    {notice && (
                        <Notice role="status" className="mt-4">
                            {notice}
                        </Notice>
                    )}

                    {googleFailed && (
                        <p role="alert" className="mt-4 flex items-start gap-2 font-medium text-danger">
                            <Warning className="mt-0.5 size-5 shrink-0" /> {text.failed}
                        </p>
                    )}

                    {canSignIn ? (
                        <>
                            {emailSignIn && (
                                <form onSubmit={submit} noValidate className="mt-5">
                                    <ErrorSummary problems={problems} ref={summary} />
                                    {unverified && (
                                        <Notice role="alert" tone="danger" className="mb-5">
                                            <p className="font-semibold">{text.unverifiedTitle}</p>
                                            <p className="mt-1">{text.unverifiedBody}</p>
                                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                                <button type="button" onClick={resend} disabled={resent} className={buttonClass('outline', 'min-h-11 px-4')}>
                                                    <Mail className="size-4" /> {text.resend}
                                                </button>
                                                <button type="button" onClick={() => navigate('/verify-email', { state: { email: email.trim() } })} className="press min-h-11 text-sm underline underline-offset-4">
                                                    {copy.verify.title}
                                                </button>
                                            </div>
                                            {resent && (
                                                <p role="status" className="mt-2 text-sm text-muted">
                                                    {copy.verify.resent}
                                                </p>
                                            )}
                                        </Notice>
                                    )}
                                    <div className="grid gap-5">
                                        <TextField
                                            id={fieldIds.email}
                                            name="email"
                                            label={auth.email}
                                            value={email}
                                            onChange={setEmail}
                                            error={fieldErrors[fieldIds.email]}
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
                                        <PasswordField id={fieldIds.password} label={auth.password} value={password} onChange={setPassword} error={fieldErrors[fieldIds.password]} autoComplete="current-password" />
                                    </div>
                                    <div className="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                                        <button type="submit" disabled={busy} className={buttonClass('accent', 'w-full sm:w-auto sm:min-w-[12rem]')}>
                                            <ArrowRight className="size-5" /> {busy ? text.submitting : text.submit}
                                        </button>
                                        <Link to="/forgot-password" className="press inline-flex min-h-11 items-center text-[0.95rem] underline underline-offset-4">
                                            {text.forgot}
                                        </Link>
                                    </div>
                                </form>
                            )}

                            {emailSignIn && (site.auth.googleEnabled || localSignIn) && <OrDivider />}

                            {site.auth.googleEnabled && <GoogleButton href={href} className={`${emailSignIn ? '' : 'mt-5 '}w-full sm:w-auto sm:min-w-[18rem]`} />}
                            {localSignIn && (
                                <>
                                    <a href={href} className={buttonClass('accent', `${emailSignIn || site.auth.googleEnabled ? 'mt-3' : 'mt-5'} w-full sm:w-auto sm:min-w-[18rem]`)}>
                                        <User className="size-5" /> {text.sample}
                                    </a>
                                    <p className="mt-3 text-sm leading-relaxed text-muted">{text.sampleNote}</p>
                                </>
                            )}

                            {emailSignIn && (
                                <p className="mt-6 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-line pt-4 text-[0.95rem] text-muted">
                                    <Check className="size-4 shrink-0 text-accent" /> {text.newHere}
                                    <Link to={accountPagePath('register', back)} className="press inline-flex min-h-11 items-center font-semibold text-text underline underline-offset-4">
                                        {text.create}
                                    </Link>
                                </p>
                            )}
                        </>
                    ) : (
                        <p className="mt-3 leading-relaxed text-muted">{text.unavailable}</p>
                    )}
                </section>

                <p className="mt-6">
                    <a href={`${site.base}/admin/login`} className="press inline-flex min-h-11 items-center text-[0.95rem] text-muted underline underline-offset-4 hover:text-text">
                        {text.team}
                    </a>
                </p>
            </div>
        </>
    );
}
