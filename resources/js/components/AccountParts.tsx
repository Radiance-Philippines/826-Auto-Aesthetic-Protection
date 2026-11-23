import type { ReactNode } from 'react';
import { Link, useLocation } from 'react-router-dom';

import { referralLink, useCopy } from '../lib/account';
import { copy } from '../lib/copy';
import { buttonClass } from './Button';
import { Check, Copy, Link as LinkIcon, Warning } from './Icons';

const text = copy.me;

/** A grey block with the site's shimmer, where text or a card is on its way */
export function Bone({ className = '' }: { className?: string }) {
    return (
        <span aria-hidden="true" className={`relative block overflow-hidden rounded-control bg-surface-3 ${className}`}>
            <span className="skeleton" />
        </span>
    );
}

/** The page while its data is on the way: bones in the shape of the content, one polite "Loading" for screen readers */
export function Loading({ children }: { children: ReactNode }) {
    return (
        <div role="status" aria-live="polite">
            <span className="sr-only">{text.loading}</span>
            {children}
        </div>
    );
}

/** A card-shaped bone */
export function CardBone({ className = 'h-36' }: { className?: string }) {
    return (
        <div aria-hidden="true" className="panel p-5 sm:p-6">
            <Bone className="h-4 w-1/3" />
            <Bone className="mt-4 h-6 w-2/3" />
            <Bone className={`mt-4 ${className}`} />
        </div>
    );
}

/** The request failed, or the sign-in ended: say so, and what to do */
export function Problem({ kind, onRetry }: { kind: 'failed' | 'signedOut'; onRetry?: () => void }) {
    const location = useLocation();

    return (
        <div role="alert" className="panel flex flex-wrap items-center gap-3 p-5 font-medium text-danger sm:p-6">
            <Warning className="size-5 shrink-0" />
            <span className="min-w-0 flex-1">{kind === 'signedOut' ? text.signedOut : text.failed}</span>
            {kind === 'signedOut' ? (
                <Link to={`/login?return=${encodeURIComponent(`${location.pathname}${location.search}`)}`} className={buttonClass('outline', 'min-h-11 px-4 text-text')}>
                    {copy.account.signIn}
                </Link>
            ) : (
                <button type="button" onClick={onRetry} className={buttonClass('outline', 'min-h-11 px-4 text-text')}>
                    {text.retry}
                </button>
            )}
        </div>
    );
}

export function Section({ id, title, children, action }: { id: string; title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <section aria-labelledby={id} className="mt-10 first:mt-0">
            <div className="flex items-center justify-between gap-4">
                <h2 id={id} className="eyebrow text-muted">
                    {title}
                </h2>
                {action}
            </div>
            <div className="mt-4">{children}</div>
        </section>
    );
}

/** The customer's referral code and link, each with its own copy button */
export function ShareCode({ code, body, showLink = false }: { code: string; body?: string; showLink?: boolean }) {
    const { copied, failed, run } = useCopy();
    const link = referralLink(code);

    return (
        <div className="panel p-5 sm:p-6">
            <p className="price text-3xl tracking-wide tabular">{code}</p>
            {body && <p className="mt-2 max-w-xl text-[0.95rem] leading-relaxed text-muted">{body}</p>}
            {showLink && (
                <p className="mt-4 text-sm text-muted">
                    <span className="eyebrow mb-1 block text-[0.68rem]">{copy.me.rewards.linkLabel}</span>
                    <span className="break-all text-text tabular">{link}</span>
                </p>
            )}
            <div className="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" onClick={() => run('code', code)} className={buttonClass('outline', 'min-h-11 px-4')}>
                    {copied === 'code' ? <Check className="size-4" /> : <Copy className="size-4" />} {text.copyCode}
                </button>
                <button type="button" onClick={() => run('link', link)} className={buttonClass('outline', 'min-h-11 px-4')}>
                    {copied === 'link' ? <Check className="size-4" /> : <LinkIcon className="size-4" />} {text.copyLink}
                </button>
                <span role="status" className="text-sm font-medium text-muted">
                    {failed ? text.copyFailed : copied === 'code' ? text.copied : copied === 'link' ? text.linkCopied : ''}
                </span>
            </div>
        </div>
    );
}
