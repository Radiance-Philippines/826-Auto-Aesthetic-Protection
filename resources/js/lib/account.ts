import { useCallback, useEffect, useState } from 'react';

import type { MyProfile, MyRewards, UnitDetail, WarrantyUnit } from '../types';
import { type ApiResult, getJson } from './api';
import { copyText } from './bookings';
import { site } from './site';

export const fetchProfile = () => getJson<MyProfile>('/me');

export const fetchUnit = (id: string | number) => getJson<UnitDetail>(`/my-vehicles/${encodeURIComponent(String(id))}`);

export const fetchRewards = () => getJson<MyRewards>('/my-rewards');

export const fetchWarranty = () => getJson<WarrantyUnit[]>('/my-warranty');

/** What a customer shares: this site's /r/<code>, which opens the booking page with the code filled in */
export const referralLink = (code: string) => `${window.location.origin}${site.base}/r/${encodeURIComponent(code)}`;

export type Load<T> = { state: 'loading' } | { state: 'ok'; data: T } | { state: 'failed' } | { state: 'signedOut' } | { state: 'missing' };

/**
 * One request for a page: loading, the answer, or what went wrong. `reload`
 * asks again (the Try again button); a quiet look keeps what's on screen.
 */
export function useLoad<T>(fetcher: () => Promise<ApiResult<T>>, key: unknown = null): { load: Load<T>; reload: () => void; refresh: () => void } {
    const [load, setLoad] = useState<Load<T>>({ state: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const [quiet, setQuiet] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setLoad({ state: 'loading' });

        fetcher().then((result) => {
            if (!cancelled) {
                setLoad(sort(result));
            }
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [attempt, key]);

    useEffect(() => {
        if (quiet === 0) {
            return;
        }

        let cancelled = false;

        fetcher().then((result) => {
            if (!cancelled && result.kind === 'ok') {
                setLoad({ state: 'ok', data: result.data });
            }
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [quiet]);

    return { load, reload: useCallback(() => setAttempt((count) => count + 1), []), refresh: useCallback(() => setQuiet((count) => count + 1), []) };
}

function sort<T>(result: ApiResult<T>): Load<T> {
    switch (result.kind) {
        case 'ok':
            return { state: 'ok', data: result.data };
        case 'signIn':
            return { state: 'signedOut' };
        case 'notFound':
            return { state: 'missing' };
        default:
            return { state: 'failed' };
    }
}

/** A copy button's state: which thing was copied for two seconds, and whether copying failed */
export function useCopy() {
    const [copied, setCopied] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    const run = async (what: string, value: string) => {
        const ok = await copyText(value);
        setFailed(!ok);
        setCopied(ok ? what : null);

        if (ok) {
            window.setTimeout(() => setCopied((current) => (current === what ? null : current)), 2000);
        }
    };

    return { copied, failed, run };
}
