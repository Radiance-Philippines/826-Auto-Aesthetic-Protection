import type { ReactNode } from 'react';

import { boot } from '../lib/boot';
import { Icon } from '../ui/icons';

const mark = (size: number, type: 'avif' | 'webp') => `${boot.base}/brand/826-mark-${size}.${type} ${size}w`;

/**
 * Two columns from 64rem: the brand beside the form. Narrower: the brand is a
 * short header and the form follows on the first screen.
 */
export default function Layout({ children }: { children: ReactNode }) {
    return (
        <div className="grid min-h-dvh grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,11fr)_minmax(0,9fr)] lg:grid-rows-1">
            {/* "dark" on the panel: the console's dark tokens in both themes, as the teal mark is drawn for it */}
            <header className="auth-brand dark relative isolate flex flex-col items-center justify-center gap-3 overflow-hidden border-b border-border bg-card px-6 py-6 text-center text-foreground lg:border-e lg:border-b-0 lg:px-12 lg:py-12">
                <picture>
                    <source type="image/avif" srcSet={`${mark(240, 'avif')}, ${mark(480, 'avif')}`} sizes="(min-width: 64rem) 24rem, 9rem" />
                    <source type="image/webp" srcSet={`${mark(240, 'webp')}, ${mark(480, 'webp')}`} sizes="(min-width: 64rem) 24rem, 9rem" />
                    <img src={`${boot.base}/brand/826-mark-480.webp`} alt="826" width={480} height={133} fetchPriority="high" className="h-auto w-36 max-w-full lg:w-96" />
                </picture>
                {/* The logo's gold rule */}
                <span aria-hidden="true" className="h-0.5 w-36 rounded-full bg-gold lg:mt-2 lg:w-96 lg:max-w-full" />
                <p className="auth-name text-[0.6875rem] font-bold uppercase italic lg:text-base">Auto Aesthetic &amp; Protection</p>
                <a href={`${boot.base}/`} className="absolute bottom-6 hidden min-h-11 items-center gap-2 rounded-md px-2 text-sm text-muted-foreground hover:text-foreground lg:inline-flex">
                    <Icon name="external-link" />
                    View the website
                </a>
            </header>
            <main className="flex min-w-0 items-start justify-center px-4 py-8 sm:px-6 lg:items-center lg:py-12">
                <div className="auth-form w-full max-w-sm">{children}</div>
            </main>
        </div>
    );
}

/** The page's title and the line under it */
export function Heading({ title, children }: { title: string; children?: ReactNode }) {
    return (
        <div className="mb-6">
            <h1 className="page-title text-2xl">{title}</h1>
            {children && <p className="mt-1.5 text-muted-foreground">{children}</p>}
        </div>
    );
}

export function Notice({ tone, children }: { tone: 'error' | 'success'; children: ReactNode }) {
    return (
        <div role={tone === 'error' ? 'alert' : 'status'} className={`mb-4 flex items-start gap-2 rounded-lg border px-3.5 py-3 text-sm ${tone === 'error' ? 'border-destructive/40 bg-card text-destructive' : 'border-border bg-selected text-selected-foreground'}`}>
            <Icon name={tone === 'error' ? 'circle-alert' : 'circle-check'} className="mt-0.5 size-4" />
            <div className="min-w-0 break-words">{children}</div>
        </div>
    );
}

export const linkClass = 'inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary underline-offset-4 hover:underline pointer-coarse:min-h-11';

export const backToSignIn = (
    <a href={`${boot.base}/console/login`} className={linkClass}>
        <Icon name="arrow-left" />
        Back to sign in
    </a>
);
