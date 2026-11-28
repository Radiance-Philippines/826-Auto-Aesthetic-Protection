import type { ReactNode } from 'react';

import { Button } from './Button';
import { cx } from './cx';
import { Icon, type IconName } from './icons';

/** The first load: grey blocks where the content goes, "Loading" for screen readers */
export function Loading({ rows = 3, className }: { rows?: number; className?: string }) {
    return (
        <div role="status" className={cx('space-y-2', className)}>
            <span className="sr-only">Loading…</span>
            {Array.from({ length: rows }, (_, index) => (
                <div key={index} aria-hidden="true" className="h-12 animate-pulse rounded-md bg-muted" />
            ))}
        </div>
    );
}

export function EmptyState({ icon, title, children, action }: { icon: IconName; title: string; children?: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center">
            <Icon name={icon} className="size-8 text-muted-foreground" />
            <p className="font-semibold">{title}</p>
            {children && <p className="max-w-prose text-sm text-muted-foreground">{children}</p>}
            {action}
        </div>
    );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
    return (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-destructive/40 bg-card px-4 py-3 text-sm">
            <p className="flex items-center gap-2 text-destructive">
                <Icon name="circle-alert" />
                {message}
            </p>
            <Button icon="refresh-cw" size="sm" onClick={onRetry}>
                Try again
            </Button>
        </div>
    );
}

export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'warning' | 'info' | 'selected'; children: ReactNode }) {
    const tones = { neutral: 'bg-muted text-foreground', warning: 'bg-warning text-warning-foreground', info: 'bg-info text-info-foreground', selected: 'bg-selected text-selected-foreground' };

    return <span className={cx('inline-flex w-fit items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone])}>{children}</span>;
}
