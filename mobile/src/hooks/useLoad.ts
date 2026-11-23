import { useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import type { ApiResult } from '@/api';

export type Loaded<T> = { data: T | null; error: ApiResult<unknown> | null; loading: boolean; reload: () => Promise<void> };

/**
 * Loads a screen's data when the screen comes into focus (so it is fresh
 * after booking something) and on pull to refresh. Old data stays on screen
 * while it reloads: the skeleton shows only the first time.
 */
export function useLoad<T, R = T>(load: () => Promise<ApiResult<R>>, map?: (raw: R) => T): Loaded<T> {
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState<ApiResult<unknown> | null>(null);
    const [loading, setLoading] = useState(true);
    const loadRef = useRef(load);
    loadRef.current = load;

    const reload = useCallback(async () => {
        const result = await loadRef.current();

        if (result.kind === 'ok') {
            setData(map ? map(result.data) : (result.data as unknown as T));
            setError(null);
        } else {
            setError(result);
        }

        setLoading(false);
        // map is a stable module-level function at every call site
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useFocusEffect(
        useCallback(() => {
            void reload();
        }, [reload]),
    );

    return { data, error: data ? null : error, loading: loading && !data && !error, reload };
}
