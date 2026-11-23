import type { Guest } from '../types';
import { copy } from './copy';
import { site } from './site';

/** Laravel's token, from the meta tag in site.blade.php: every POST sends it */
export const csrfToken = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

export const guest: Guest | null = site.auth.guest;

/**
 * Where signing in starts: Google when it's set up, else (local
 * development only) the test customer. Null when neither is there.
 */
const signInBase: string | null = site.auth.googleEnabled ? site.auth.signInUrl : site.auth.localSignInUrl;

/** Whether this copy signs in the local test customer instead of Google */
export const localSignIn = !site.auth.googleEnabled && Boolean(site.auth.localSignInUrl);

/** A reservation or request needs a signed-in customer (a demo saves nothing, so it doesn't) */
export const signInNeeded = !guest && !site.demo;

/** Signing in is possible on this copy: with an email and password, with Google, or (local and demo) as the sample customer */
export const canSignIn = site.auth.emailEnabled || signInBase !== null;

/** Own accounts (email and password) work on this copy */
export const emailSignIn = site.auth.emailEnabled;

/**
 * Sign-in, coming back to `path` (site-relative, without site.base:
 * "/book?step=7").
 */
export function signInHref(path: string): string {
    const url = new URL(signInBase ?? site.auth.signInUrl, window.location.origin);
    url.searchParams.set('return', path.startsWith('/') ? path : `/${path}`);

    return url.toString();
}

/**
 * The site's sign-in or create-account page, coming back to `path`
 * (site-relative, without site.base). No path: the account.
 */
export function accountPagePath(page: 'login' | 'register', path?: string | null): string {
    return path ? `/${page}?return=${encodeURIComponent(path.startsWith('/') ? path : `/${path}`)}` : `/${page}`;
}

/** What a POST to /auth/* came back with: the status and the JSON (null when there was none) */
export type AuthReply<T = Record<string, unknown>> = { status: number; body: (T & { message?: string; errors?: Record<string, string[]>; code?: string }) | null };

/**
 * Sends the sign-in, sign-up, forgot and reset forms (POST /auth/…, with the
 * CSRF token). Status 0 when the network failed.
 */
export async function postAuth<T = Record<string, unknown>>(path: string, payload: unknown): Promise<AuthReply<T>> {
    try {
        const response = await fetch(`${site.base}/auth/${path}`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
        });

        return { status: response.status, body: await response.json().catch(() => null) };
    } catch {
        return { status: 0, body: null };
    }
}

/**
 * The same checks the server makes on a new password: 10 or more
 * characters, a letter, a digit, not the email address. Null when fine.
 */
export function passwordProblem(password: string, email = ''): string | null {
    if (password.length < 10 || !/\p{L}/u.test(password) || !/\d/.test(password)) {
        return copy.auth.passwordRules;
    }

    if (password.length > 72) {
        return copy.auth.passwordTooLong;
    }

    return email && password.toLowerCase() === email.trim().toLowerCase() ? copy.auth.passwordIsEmail : null;
}

/**
 * A `?return=` path from the address, only if it stays on this site (the
 * server's safeReturn rules); never a sign-in page itself. Null otherwise.
 */
export function safeReturn(raw: string | null): string | null {
    const path = (raw ?? '').trim();

    if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\') || /[\u0000-\u001f]/.test(path) || /^\/(?:login|register|forgot-password|reset-password|verify-email)(?:[/?#]|$)/.test(path)) {
        return null;
    }

    return path;
}

/**
 * Ends the session. Whatever the answer, the page is reloaded or left
 * after it, so it shows the real state.
 */
export async function signOut(): Promise<void> {
    try {
        await fetch(site.auth.signOutUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            credentials: 'same-origin',
        });
    } catch {
        // Offline: the reload shows whether it went through
    }
}

/**
 * After signing out: My bookings is private, so home; anywhere else, the
 * same page again.
 */
export function afterSignOut(pathname: string): void {
    if (pathname.startsWith('/bookings') || pathname.startsWith('/account')) {
        window.location.assign(`${site.base}/`);
        return;
    }

    window.location.reload();
}

/** "Juan dela Cruz" → "JC", "juan" → "J" */
export function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    const first = words[0][0];
    const last = words.length > 1 ? words[words.length - 1][0] : '';

    return `${first}${last}`.toUpperCase();
}
