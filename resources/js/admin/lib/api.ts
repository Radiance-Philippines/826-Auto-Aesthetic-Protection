import { apiBase, csrfToken } from './boot';

/** Laravel's validation errors: field → messages */
export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors: FieldErrors = {},
    ) {
        super(message);
    }
}

const MESSAGES: Record<number, string> = {
    0: 'Can’t reach the server. Check your connection and try again.',
    401: 'You’re signed out. Reload the page to sign in again.',
    403: 'Your role doesn’t include this.',
    404: 'That isn’t there any more. Reload the page.',
    419: 'This page has been open too long. Reload it.',
    429: 'Too many requests. Wait a minute and try again.',
};

const RELOADED = 'console-reloaded-at';

/** Signed out or a stale page: loading it again signs in or brings a new token. Once, so it cannot loop. */
function reloadOnce(): void {
    try {
        if (Date.now() - Number(sessionStorage.getItem(RELOADED)) < 10_000) {
            return;
        }

        sessionStorage.setItem(RELOADED, String(Date.now()));
    } catch {
        return;
    }

    window.location.reload();
}

/** A call to /console/api/{panel}{path}; anything but success throws an ApiError with words to show */
export async function api<T = unknown>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    let response: Response;

    try {
        response = await fetch(`${apiBase}${path}`, {
            method,
            headers: {
                Accept: 'application/json',
                ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
            signal,
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        throw new ApiError(0, MESSAGES[0]);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const data = (await response.json().catch(() => null)) as (T & { message?: string; errors?: FieldErrors; redirect?: string }) | null;

    if (response.ok && data !== null) {
        return data;
    }

    if (response.status === 401 || response.status === 419) {
        reloadOnce();
    }

    // Two-factor sign-in is due: Filament's set-up page
    if (response.status === 403 && data?.redirect) {
        window.location.assign(data.redirect);
    }

    const own = [403, 422].includes(response.status) ? data?.message : null;

    throw new ApiError(response.status, own || MESSAGES[response.status] || 'Something went wrong on our side. Try again.', data?.errors ?? {});
}

/** The first message of each field */
export const fieldErrors = (error: unknown): Record<string, string> =>
    error instanceof ApiError ? Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]])) : {};

export const errorMessage = (error: unknown): string => (error instanceof Error ? error.message : 'Something went wrong. Try again.');
