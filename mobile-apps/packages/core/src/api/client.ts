/**
 * One typed API client for the app. It speaks to the Laravel API with a
 * bearer token, and sorts every response by what a screen does next. The
 * `fetch` it uses is injectable: jest and EXPO_PUBLIC_MOCK=1 pass the mock
 * adapter in src/api/mock.ts.
 */
export type FieldErrors = Record<string, string[]>;

export type ApiResult<T> =
    | { kind: 'ok'; data: T }
    /** 401: no or a bad token. Sign in again. */
    | { kind: 'signIn'; message: string | null }
    /** 422: show each message by its field */
    | { kind: 'invalid'; errors: FieldErrors; message: string | null }
    /** 409: busy, the hold ran out, can't cancel anymore */
    | { kind: 'conflict'; message: string | null }
    | { kind: 'notFound' }
    /** 403 in `team` mode: this role has no access, or the app is not for the role (`code: 'app_not_for_role'`, `suggestApp`) */
    | { kind: 'forbidden'; message: string | null; code?: string | null; suggestApp?: string | null }
    /** 403 that carries a `code` (email_unverified): the server says why and what is possible */
    | { kind: 'blocked'; code: string; message: string | null; email: string | null; canResend: boolean }
    /** 429; `retryAfter` (seconds) and `code: 'locked'` come with a sign-in lock */
    | { kind: 'tooMany'; code?: string | null; retryAfter?: number | null; message?: string | null }
    /** The phone has no connection or the server did not answer */
    | { kind: 'offline' }
    | { kind: 'failed'; message: string | null; code?: string | null };

type Body = Record<string, unknown> & { message?: string; errors?: FieldErrors };

/** `customer`: 403 is "not found" (or `blocked` with a code). `team`: 403 is `forbidden`, and a 429 message is shown as the server wrote it. */
export type ClientMode = 'customer' | 'team';

export function sortResponse<T>(status: number, body: Body | null, mode: ClientMode = 'customer'): ApiResult<T> {
    const message = typeof body?.message === 'string' ? body.message : null;
    const code = typeof body?.code === 'string' ? body.code : null;

    if (status >= 200 && status < 300) {
        return { kind: 'ok', data: (body ?? {}) as T };
    }

    if (status === 401) return { kind: 'signIn', message };
    if (status === 422) return { kind: 'invalid', errors: body?.errors ?? {}, message };
    if (status === 409) return { kind: 'conflict', message };
    if (status === 403 && mode === 'team') return { kind: 'forbidden', message, ...(code ? { code } : {}), ...(typeof body?.suggestApp === 'string' ? { suggestApp: body.suggestApp } : {}) };
    if (status === 403 && code) return { kind: 'blocked', code, message, email: typeof body?.email === 'string' ? body.email : null, canResend: body?.canResend === true };
    if (status === 404 || status === 403) return { kind: 'notFound' };
    if (status === 429) return { kind: 'tooMany', code, message, retryAfter: typeof body?.retryAfter === 'number' ? body.retryAfter : null };

    return { kind: 'failed', message, code };
}

/** "?a=1&b[]=2&b[]=3" from a flat object; empty values are left out */
export function query(params: Record<string, string | number | null | undefined | (string | number)[]>): string {
    const parts: string[] = [];

    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            value.forEach((item) => parts.push(`${encodeURIComponent(`${key}[]`)}=${encodeURIComponent(String(item))}`));
        } else if (value !== null && value !== undefined && value !== '') {
            parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
        }
    }

    return parts.length ? `?${parts.join('&')}` : '';
}

export type ClientOptions = {
    /** "https://dran.dev/demo/826", no trailing slash */
    baseUrl: string;
    getToken: () => Promise<string | null> | string | null;
    /** Called once when the server says the token is no good */
    onUnauthorized?: () => void;
    fetchImpl?: typeof fetch;
    /** Milliseconds before a request is given up on */
    timeoutMs?: number;
    /** Default `customer` */
    mode?: ClientMode;
    /** Sent on every call (the team apps send `X-Team-App`) */
    headers?: Record<string, string>;
};

export type Client = ReturnType<typeof createClient>;

export function createClient({ baseUrl, getToken, onUnauthorized, fetchImpl, timeoutMs = 25000, mode = 'customer', headers: fixedHeaders }: ClientOptions) {
    const doFetch: typeof fetch = (input, init) => (fetchImpl ?? fetch)(input, init);

    async function send<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, payload?: unknown, { upload = false, auth = true } = {}): Promise<ApiResult<T>> {
        const token = auth ? await getToken() : null;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);

        try {
            const response = await doFetch(`${baseUrl}/api${path}`, {
                method,
                headers: {
                    Accept: 'application/json',
                    ...fixedHeaders,
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                    // A multipart body sets its own boundary
                    ...(payload !== undefined && !upload ? { 'Content-Type': 'application/json' } : {}),
                },
                body: payload === undefined ? undefined : upload ? (payload as FormData) : JSON.stringify(payload),
                signal: controller.signal,
            });

            let body: Body | null = null;

            try {
                body = (await response.json()) as Body;
            } catch {
                body = null;
            }

            const result = sortResponse<T>(response.status, body, mode);

            if (result.kind === 'signIn' && token) {
                onUnauthorized?.();
            }

            return result;
        } catch {
            return { kind: 'offline' };
        } finally {
            clearTimeout(timer);
        }
    }

    return {
        get: <T>(path: string, options?: { auth?: boolean }) => send<T>('GET', path, undefined, options),
        post: <T>(path: string, payload?: unknown, options?: { auth?: boolean }) => send<T>('POST', path, payload ?? {}, options),
        patch: <T>(path: string, payload: unknown) => send<T>('PATCH', path, payload),
        upload: <T>(path: string, form: FormData) => send<T>('POST', path, form, { upload: true }),
    };
}

/** The sentence a screen shows for a result that is not ok */
/** `team`: a 429 shows the server's own sentence (the customer app only does for a sign-in lock) */
export function problemText(result: ApiResult<unknown>, mode: ClientMode = 'customer'): string {
    switch (result.kind) {
        case 'ok':
            return '';
        case 'offline':
            return 'No connection. Check your internet and try again.';
        case 'tooMany':
            return (result.code === 'locked' || mode === 'team') && result.message ? result.message : 'Too many tries in a row. Wait a minute, then try again.';
        case 'blocked':
            return result.message ?? 'That isn’t allowed right now.';
        case 'notFound':
            return 'We couldn’t find that.';
        case 'forbidden':
            return result.message ?? 'Your account can’t do that.';
        case 'signIn':
            return result.message ?? 'Please sign in again.';
        case 'invalid': {
            const first = Object.values(result.errors)[0]?.[0];

            return first ?? result.message ?? 'Check the highlighted fields.';
        }
        default:
            return result.message ?? 'That didn’t go through. Try again in a moment.';
    }
}
