import { c, createClient, palettes, problemText, sortResponse } from '../src';
import { createTokenStore } from '../src/lib/tokens';

const reply = (status: number, body: unknown) => jest.fn(async () => ({ status, json: async () => body }) as Response);

describe('team mode', () => {
    it('sorts a 403 as forbidden (with its code and the app that fits), the customer mode keeps "not found"', () => {
        const body = { message: 'This app is for 826 Sales. Use 826 Operations.', code: 'app_not_for_role', suggestApp: 'operations' };
        expect(sortResponse(403, body, 'team')).toEqual({ kind: 'forbidden', ...body });
        expect(sortResponse(403, { message: 'Nope' })).toEqual({ kind: 'notFound' });
        expect(problemText(sortResponse(403, body, 'team'))).toBe(body.message);
    });

    it('shows a 429 sentence only in team mode, or for a sign-in lock', () => {
        const limited = sortResponse(429, { message: 'Slow down.' }, 'team');
        expect(problemText(limited, 'team')).toBe('Slow down.');
        expect(problemText(sortResponse(429, { message: 'Slow down.' }))).toBe('Too many tries in a row. Wait a minute, then try again.');
        expect(problemText(sortResponse(429, { message: 'Locked for 5 minutes.', code: 'locked' }))).toBe('Locked for 5 minutes.');
    });

    it('sends the fixed headers on every call, next to the token', async () => {
        const fetchImpl = reply(200, {});
        const client = createClient({ baseUrl: 'https://x.test', getToken: () => 'tok', fetchImpl: fetchImpl as unknown as typeof fetch, headers: { 'X-Team-App': 'sales' }, mode: 'team' });
        await client.get('/staff/me');
        await client.post('/staff/sign-in', {}, { auth: false });
        const sent = fetchImpl.mock.calls.map((call) => (call as unknown as [string, { headers: Record<string, string> }])[1].headers);
        expect(sent[0]).toMatchObject({ 'X-Team-App': 'sales', Authorization: 'Bearer tok' });
        expect(sent[1]).toMatchObject({ 'X-Team-App': 'sales' });
        expect(sent[1].Authorization).toBeUndefined();
    });
});

describe('theme', () => {
    it('gives the dark-only apps the dark palette as plain values', () => expect(c).toBe(palettes.dark));
});

describe('token store', () => {
    it('keeps one token per key', async () => {
        const a = createTokenStore('a.token');
        const b = createTokenStore('b.token');
        await a.saveToken('one');
        expect(await a.getToken()).toBe('one');
        expect(await b.getToken()).not.toBe('one');
        await a.clearToken();
        expect(await a.getToken()).toBeNull();
    });
});
