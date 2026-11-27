import { useCallback, useEffect, useState } from 'react';

import { api, ApiError, errorMessage } from './api';

/**
 * A GET that screens can ask again. The last answer stays on screen while the
 * next one loads (`loading`), so a refresh or a step to another day does not
 * blank the page.
 */
export function useResource<T>(path: string) {
    const [state, setState] = useState<{ data: T | null; error: string | null; loading: boolean }>({ data: null, error: null, loading: true });
    const [version, setVersion] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true }));

        api<T>('GET', path, undefined, controller.signal).then(
            (data) => {
                if (!controller.signal.aborted) {
                    setState({ data, error: null, loading: false });
                }
            },
            (error: unknown) => {
                if (!controller.signal.aborted) {
                    // An access error means what was shown is no longer theirs to see
                    const gone = error instanceof ApiError && [403, 404].includes(error.status);
                    setState((current) => ({ data: gone ? null : current.data, error: errorMessage(error), loading: false }));
                }
            },
        );

        return () => controller.abort();
    }, [path, version]);

    const reload = useCallback(() => setVersion((current) => current + 1), []);

    return { ...state, reload };
}
