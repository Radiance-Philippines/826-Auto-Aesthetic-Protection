import { API_URL, MOCK } from '@/lib/config';
import { createClient } from './client';
import { createApi } from './endpoints';
import { createMockFetch } from './mock';
import { getToken } from '@/lib/storage';

let onUnauthorized: (() => void) | undefined;

/** The auth context sets this so a 401 on any call signs the person out */
export const setUnauthorizedHandler = (handler: (() => void) | undefined) => {
    onUnauthorized = handler;
};

export const api = createApi(
    createClient({
        baseUrl: API_URL,
        getToken,
        onUnauthorized: () => onUnauthorized?.(),
        fetchImpl: MOCK ? createMockFetch() : undefined,
    }),
);

export * from './types';
export { problemText, type ApiResult } from './client';
