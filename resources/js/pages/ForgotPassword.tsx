import { type FormEvent, useRef, useState } from 'react';
import { Link } from 'react-router-dom';

import { AuthFoot, AuthPage, ErrorSummary, type Problem, problemsOf } from '../components/AuthParts';
import { buttonClass } from '../components/Button';
import { Notice, TextField } from '../components/Form';
import { Mail } from '../components/Icons';
import { postAuth } from '../lib/auth';
import { copy } from '../lib/copy';
import { emailProblem } from '../lib/customerAuth';
import usePageTitle from '../lib/usePageTitle';

const text = copy.forgot;
const id = 'forgot-email';

/** Asks for the reset link. The answer is the same for every address. */
export default function ForgotPassword() {
    usePageTitle(text.title);
    const [email, setEmail] = useState('');
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(false);
    const [problems, setProblems] = useState<Problem[]>([]);
    const [error, setError] = useState<string | null>(null);
    const summary = useRef<HTMLDivElement>(null);

    const fail = (list: Problem[], message: string | null) => {
        setProblems(list);
        setError(message);
        requestAnimationFrame(() => summary.current?.focus());
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSent(false);
        const problem = emailProblem(email);

        if (problem) {
            fail([{ field: id, message: problem }], problem);
            return;
        }

        setBusy(true);
        const reply = await postAuth('forgot-password', { email: email.trim() });
        setBusy(false);

        if (reply.status === 200) {
            setProblems([]);
            setError(null);
            setSent(true);
            return;
        }

        const sorted = problemsOf(reply, { email: id });
        fail(sorted.problems, sorted.byField[id] ?? null);
    };

    return (
        <AuthPage eyebrow={text.eyebrow} title={text.title} intro={text.intro}>
            <form onSubmit={submit} noValidate className="mt-5">
                <ErrorSummary problems={problems} ref={summary} />
                {sent && (
                    <Notice role="status" className="mb-5">
                        {text.sent}
                    </Notice>
                )}
                <TextField id={id} name="email" label={copy.auth.email} value={email} onChange={setEmail} error={error} type="email" inputMode="email" autoComplete="username" autoCapitalize="none" autoCorrect="off" spellCheck={false} maxLength={190} enterKeyHint="send" required />
                <button type="submit" disabled={busy} className={buttonClass('accent', 'mt-6 w-full sm:w-auto sm:min-w-[14rem]')}>
                    <Mail className="size-5" /> {busy ? text.submitting : text.submit}
                </button>
            </form>
            <AuthFoot>
                <Link to="/login" className="press inline-flex min-h-11 items-center font-semibold text-text underline underline-offset-4">
                    {text.back}
                </Link>
            </AuthFoot>
        </AuthPage>
    );
}
