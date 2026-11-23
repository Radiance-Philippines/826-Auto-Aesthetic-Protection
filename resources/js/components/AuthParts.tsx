import { type ReactNode, type Ref, useState } from 'react';

import { type AuthReply } from '../lib/auth';
import { copy } from '../lib/copy';
import { TextField } from './Form';
import { PageHeader } from './Heading';
import { Eye, EyeOff, Warning } from './Icons';

const text = copy.auth;

/** A problem to list in the summary: the field it belongs to (its id) and the words */
export type Problem = { field: string | null; message: string };

/**
 * The list of what went wrong, above the form, read out when it shows
 * (role="alert"). Each line with a field jumps to it. The form focuses the
 * box after a failed send, so a screen reader starts there.
 */
export function ErrorSummary({ problems, ref }: { problems: Problem[]; ref?: Ref<HTMLDivElement> }) {
    if (problems.length === 0) {
        return null;
    }

    return (
        <div ref={ref} role="alert" tabIndex={-1} className="mb-5 rounded-card border border-danger/60 bg-danger/8 px-4 py-3.5 text-[0.95rem] outline-none focus-visible:ring-2 focus-visible:ring-danger/50">
            <p className="flex items-center gap-2 font-semibold text-danger">
                <Warning className="size-5 shrink-0" /> {text.problemTitle}
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-9">
                {problems.map((problem) => (
                    <li key={`${problem.field}-${problem.message}`}>
                        {problem.field ? (
                            <a
                                href={`#${problem.field}`}
                                onClick={(event) => {
                                    event.preventDefault();
                                    document.getElementById(problem.field as string)?.focus();
                                }}
                                className="underline underline-offset-3"
                            >
                                {problem.message}
                            </a>
                        ) : (
                            problem.message
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * A password box with a Show / Hide button inside it. The button is a real
 * button (keyboard, 44 px target), pressed while the password is showing.
 */
export function PasswordField({ label, value, onChange, error, hint, id, autoComplete, name = 'password' }: { label: string; value: string; onChange: (value: string) => void; error?: string | null; hint?: string; id: string; autoComplete: 'current-password' | 'new-password'; name?: string }) {
    const [shown, setShown] = useState(false);

    return (
        <TextField
            id={id}
            name={name}
            label={label}
            hint={hint}
            value={value}
            onChange={onChange}
            error={error}
            type={shown ? 'text' : 'password'}
            autoComplete={autoComplete}
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            maxLength={200}
            required
            trailing={
                <button
                    type="button"
                    onClick={() => setShown((value) => !value)}
                    aria-pressed={shown}
                    className="press inline-flex min-h-11 items-center gap-1.5 rounded-control px-2.5 text-sm font-medium text-muted hover:text-text focus-visible:outline-2 focus-visible:outline-accent"
                >
                    {shown ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
                    <span className="sr-only">{text.show}</span>
                </button>
            }
        />
    );
}

/** "or", between the email form and the other ways in */
export function OrDivider() {
    return (
        <div className="my-6 flex items-center gap-4 text-sm text-muted" role="separator" aria-label={copy.account.or}>
            <span className="h-px flex-1 bg-line" />
            <span aria-hidden="true">{copy.account.or}</span>
            <span className="h-px flex-1 bg-line" />
        </div>
    );
}

/** The server's wording for each way a send can fail, as lines for the summary */
export function problemsOf(reply: AuthReply, fields: Record<string, string>): { problems: Problem[]; byField: Record<string, string> } {
    const byField: Record<string, string> = {};
    const problems: Problem[] = [];

    if (reply.status === 422 && reply.body?.errors) {
        for (const [key, messages] of Object.entries(reply.body.errors)) {
            const id = fields[key] ?? null;
            const message = messages[0];

            if (id) {
                byField[id] = message;
            }

            problems.push({ field: id, message });
        }
    } else if (reply.status === 429) {
        problems.push({ field: null, message: reply.body?.code === 'locked' && reply.body.message ? reply.body.message : text.tooMany });
    } else if (reply.status === 419) {
        problems.push({ field: null, message: text.stale });
    } else if (reply.status === 503 && reply.body?.message) {
        problems.push({ field: null, message: reply.body.message });
    } else {
        problems.push({ field: null, message: text.failed });
    }

    return { problems, byField };
}

/** A small caption under the form, like "New here? Create an account" */
export function AuthFoot({ children }: { children: ReactNode }) {
    return <p className="mt-6 flex flex-wrap items-center gap-x-2 text-[0.95rem] text-muted">{children}</p>;
}

/** The frame of the small account pages: the page header, then one panel */
export function AuthPage({ eyebrow, title, intro, children }: { eyebrow: string; title: string; intro: ReactNode; children: ReactNode }) {
    return (
        <>
            <PageHeader eyebrow={eyebrow} title={title}>
                <p>{intro}</p>
            </PageHeader>
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-8 sm:py-14 xl:px-12">
                <section aria-labelledby="auth-title" className="panel max-w-xl p-5 sm:p-7">
                    <h2 id="auth-title" className="sr-only">
                        {title}
                    </h2>
                    {children}
                </section>
            </div>
        </>
    );
}
