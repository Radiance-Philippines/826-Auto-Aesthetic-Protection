import { API_URL, MOCK } from '../lib/config';
import { getToken } from '../lib/storage';
import { createClient, problemText as coreProblemText, type ApiResult } from '@826/core';
import { getTeamApp } from '../lib/teamApp';
import { createApi } from './endpoints';
import { createMockFetch } from './mock';

let onUnauthorized: (() => void) | undefined;

/** The auth context sets this so a 401 on any call signs the person out */
export const setUnauthorizedHandler = (handler: (() => void) | undefined) => {
    onUnauthorized = handler;
};

export const api = createApi(
    createClient({
        baseUrl: API_URL,
        mode: 'team',
        headers: { 'X-Team-App': getTeamApp().appKey },
        getToken,
        onUnauthorized: () => onUnauthorized?.(),
        fetchImpl: MOCK ? createMockFetch() : undefined,
    }),
);

export * from './types';
/** The sentence for a result that is not ok, as the team apps word it (a server message is shown as it is) */
export const problemText = (result: ApiResult<unknown>): string => coreProblemText(result, 'team');
export type { ApiResult };
