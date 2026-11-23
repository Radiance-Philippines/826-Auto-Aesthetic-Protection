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
    | { kind: 'tooMany' }
    /** The phone has no connection or the server did not answer */
    | { kind: 'offline' }
    | { kind: 'failed'; message: string | null };

type Body = Record<string, unknown> & { message?: string; errors?: FieldErrors };

export function sortResponse<T>(status: number, body: Body | null): ApiResult<T> {
    const message = typeof body?.message === 'string' ? body.message : null;

    if (status >= 200 && status < 300) {
        return { kind: 'ok', data: (body ?? {}) as T };
    }

    if (status === 401) return { kind: 'signIn', message };
    if (status === 422) return { kind: 'invalid', errors: body?.errors ?? {}, message };
    if (status === 409) return { kind: 'conflict', message };
    if (status === 404 || status === 403) return { kind: 'notFound' };
    if (status === 429) return { kind: 'tooMany' };

    return { kind: 'failed', message };
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
};

export type Client = ReturnType<typeof createClient>;

export function createClient({ baseUrl, getToken, onUnauthorized, fetchImpl, timeoutMs = 25000 }: ClientOptions) {
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

            const result = sortResponse<T>(response.status, body);

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
export function problemText(result: ApiResult<unknown>): string {
    switch (result.kind) {
        case 'ok':
            return '';
        case 'offline':
            return 'No connection. Check your internet and try again.';
        case 'tooMany':
            return 'Too many tries in a row. Wait a minute, then try again.';
        case 'notFound':
            return 'We couldn’t find that.';
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
