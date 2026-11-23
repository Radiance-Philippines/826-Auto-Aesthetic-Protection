import { type FormEvent, useEffect, useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';

import { AuthPage } from '../components/AuthParts';
import { buttonClass } from '../components/Button';
import { Notice, TextField } from '../components/Form';
import { ArrowRight, Check, Mail } from '../components/Icons';
import { accountPagePath, postAuth } from '../lib/auth';
import { copy } from '../lib/copy';
import { emailProblem } from '../lib/customerAuth';
import usePageTitle from '../lib/usePageTitle';

const text = copy.verify;
const COOLDOWN = 60;

/**
 * Two things on one address: "Check your email" (after signing up, or a
 * sign-in that needs it) with a resend button and a cooldown, and where the
 * emailed link lands (?status=verified, expired or invalid). Opening the
 * link signs no one in: the person signs in next.
 */
export default function VerifyEmail() {
    usePageTitle(text.title);
    const [params] = useSearchParams();
    const state = (useLocation().state ?? {}) as { email?: string; back?: string | null };
    const status = params.get('status');
    const [email, setEmail] = useState(state.email ?? '');
    const [wait, setWait] = useState(state.email ? COOLDOWN : 0);
    const [busy, setBusy] = useState(false);
    const [resent, setResent] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (wait <= 0) {
            return;
        }

        const timer = setTimeout(() => setWait((seconds) => seconds - 1), 1000);

        return () => clearTimeout(timer);
    }, [wait]);

    const resend = async (event: FormEvent) => {
        event.preventDefault();
        const problem = emailProblem(email);
        setError(problem);

        if (problem) {
            return;
        }

        setBusy(true);
        const reply = await postAuth('resend-verification', { email: email.trim() });
        setBusy(false);

        if (reply.status === 202) {
            setResent(true);
            setWait(COOLDOWN);
        } else {
            setError(reply.status === 429 ? copy.auth.tooMany : copy.auth.failed);
        }
    };

    if (status === 'verified') {
        return (
            <AuthPage eyebrow={text.eyebrow} title={text.verifiedTitle} intro={text.verifiedBody}>
                <Notice role="status" className="mt-5">
                    <span className="flex items-center gap-2 font-semibold">
                        <Check className="size-5 text-accent" /> {text.verifiedBody}
                    </span>
                </Notice>
                <Link to="/login" className={buttonClass('accent', 'mt-5 w-full sm:w-auto sm:min-w-[14rem]')}>
                    <ArrowRight className="size-5" /> {text.signIn}
                </Link>
            </AuthPage>
        );
    }

    const expired = status === 'expired';
    const title = expired ? text.expiredTitle : status === 'invalid' ? text.invalidTitle : text.title;
    const intro = expired ? text.expiredBody : status === 'invalid' ? text.invalidBody : state.email ? text.introWith(state.email) : text.intro;

    return (
        <AuthPage eyebrow={text.eyebrow} title={title} intro={intro}>
            <form onSubmit={resend} noValidate className="mt-5">
                {!state.email || status ? (
                    <TextField name="email" label={text.emailLabel} value={email} onChange={setEmail} error={error} type="email" inputMode="email" autoComplete="username" autoCapitalize="none" spellCheck={false} maxLength={190} required />
                ) : (
                    <p className="text-[0.95rem] text-muted">{text.spam}</p>
                )}
                <button type="submit" disabled={busy || wait > 0} className={buttonClass('outline', 'mt-5 w-full sm:w-auto sm:min-w-[14rem]')}>
                    <Mail className="size-5" /> {busy ? text.sending : wait > 0 ? text.resendIn(wait) : text.resend}
                </button>
                <p role="status" className="mt-3 min-h-6 text-sm text-muted">
                    {resent ? text.resent : ''}
                </p>
                {error && state.email && !status && <p className="text-sm font-medium text-danger">{error}</p>}
            </form>
            <p className="mt-4">
                <Link to={accountPagePath('login', state.back)} className="press inline-flex min-h-11 items-center font-semibold underline underline-offset-4">
                    {text.signIn}
                </Link>
            </p>
        </AuthPage>
    );
}
