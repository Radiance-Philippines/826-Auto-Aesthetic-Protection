import { type FormEvent, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';

import { AuthFoot, AuthPage, ErrorSummary, PasswordField, type Problem, problemsOf } from '../components/AuthParts';
import { buttonClass } from '../components/Button';
import { Notice } from '../components/Form';
import { Check } from '../components/Icons';
import { passwordProblem, postAuth } from '../lib/auth';
import { copy } from '../lib/copy';
import usePageTitle from '../lib/usePageTitle';

const text = copy.resetPassword;
const id = 'reset-password';

/** The page the emailed link opens: /reset-password/{token}?email=… */
export default function ResetPassword() {
    usePageTitle(text.title);
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const email = params.get('email') ?? '';
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [problems, setProblems] = useState<Problem[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [dead, setDead] = useState(false);
    const summary = useRef<HTMLDivElement>(null);

    const fail = (list: Problem[], message: string | null) => {
        setProblems(list);
        setError(message);
        requestAnimationFrame(() => summary.current?.focus());
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const problem = passwordProblem(password, email);

        if (problem) {
            fail([{ field: id, message: problem }], problem);
            return;
        }

        setBusy(true);
        const reply = await postAuth('reset-password', { email, token, password });

        if (reply.status === 200) {
            navigate('/login?reset=1', { replace: true });
            return;
        }

        setBusy(false);

        if (reply.status === 422 && reply.body?.errors?.token) {
            setDead(true);
            return;
        }

        const sorted = problemsOf(reply, { password: id });
        fail(sorted.problems, sorted.byField[id] ?? null);
    };

    return (
        <AuthPage eyebrow={text.eyebrow} title={text.title} intro={text.intro}>
            {dead || !email ? (
                <>
                    <Notice role="alert" tone="danger" className="mt-5">
                        {text.invalid}
                    </Notice>
                    <Link to="/forgot-password" className={buttonClass('accent', 'mt-5 w-full sm:w-auto')}>
                        {text.ask}
                    </Link>
                </>
            ) : (
                <form onSubmit={submit} noValidate className="mt-5">
                    <ErrorSummary problems={problems} ref={summary} />
                    {/* Lets the password manager save the new password for this email */}
                    <input type="email" name="email" value={email} autoComplete="username" readOnly hidden />
                    <p className="mb-5 text-[0.95rem] text-muted">{email}</p>
                    <PasswordField id={id} label={copy.auth.newPassword} hint={copy.auth.passwordHint} value={password} onChange={setPassword} error={error} autoComplete="new-password" />
                    <button type="submit" disabled={busy} className={buttonClass('accent', 'mt-6 w-full sm:w-auto sm:min-w-[14rem]')}>
                        <Check className="size-5" /> {busy ? text.submitting : text.submit}
                    </button>
                </form>
            )}
            <AuthFoot>
                <Link to="/login" className="press inline-flex min-h-11 items-center font-semibold text-text underline underline-offset-4">
                    {copy.forgot.back}
                </Link>
            </AuthFoot>
        </AuthPage>
    );
}
