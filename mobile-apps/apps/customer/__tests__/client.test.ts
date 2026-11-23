import { createClient, problemText, query, sortResponse } from '@826/core';
import { createApi } from '@/api/endpoints';
import { createMockFetch } from '@/api/mock';

const reply = (status: number, body: unknown) => jest.fn(async () => ({ status, json: async () => body }) as Response) as unknown as typeof fetch;

describe('sortResponse', () => {
    it('sorts statuses by what a screen does next', () => {
        expect(sortResponse(200, { a: 1 })).toEqual({ kind: 'ok', data: { a: 1 } });
        expect(sortResponse(401, null).kind).toBe('signIn');
        expect(sortResponse(422, { errors: { name: ['Add your name'] }, message: 'bad' })).toEqual({ kind: 'invalid', errors: { name: ['Add your name'] }, message: 'bad' });
        expect(sortResponse(409, { message: 'Held' })).toEqual({ kind: 'conflict', message: 'Held' });
        expect(sortResponse(404, null).kind).toBe('notFound');
        expect(sortResponse(403, null).kind).toBe('notFound');
        expect(sortResponse(429, null).kind).toBe('tooMany');
        expect(sortResponse(500, null).kind).toBe('failed');
    });
});

describe('query', () => {
    it('leaves empty values out and repeats arrays', () => {
        expect(query({ branch: 1, services: [3, 4], month: undefined, key: '' })).toBe('?branch=1&services%5B%5D=3&services%5B%5D=4');
        expect(query({})).toBe('');
    });
});

describe('client', () => {
    it('sends the bearer token and JSON', async () => {
        const fetchImpl = reply(200, { ok: true });
        const client = createClient({ baseUrl: 'https://x.test/demo/826', getToken: () => 'tok', fetchImpl });
        await client.post('/app/sign-out');
        const [url, init] = (fetchImpl as unknown as jest.Mock).mock.calls[0];
        expect(url).toBe('https://x.test/demo/826/api/app/sign-out');
        expect(init.headers).toMatchObject({ Authorization: 'Bearer tok', Accept: 'application/json', 'Content-Type': 'application/json' });
    });

    it('sends no token on public calls, and no JSON type on uploads', async () => {
        const fetchImpl = reply(200, {});
        const client = createClient({ baseUrl: 'https://x.test', getToken: () => 'tok', fetchImpl });
        await client.get('/app/catalog', { auth: false });
        await client.upload('/my-bookings/1/payment', new FormData());
        const calls = (fetchImpl as unknown as jest.Mock).mock.calls;
        expect(calls[0][1].headers.Authorization).toBeUndefined();
        expect(calls[1][1].headers['Content-Type']).toBeUndefined();
        expect(calls[1][1].headers.Authorization).toBe('Bearer tok');
    });

    it('calls onUnauthorized on a 401 with a token', async () => {
        const onUnauthorized = jest.fn();
        const client = createClient({ baseUrl: 'https://x.test', getToken: () => 'old', onUnauthorized, fetchImpl: reply(401, { message: 'Please sign in.' }) });
        expect((await client.get('/app/me')).kind).toBe('signIn');
        expect(onUnauthorized).toHaveBeenCalledTimes(1);
    });

    it('does not sign out when no token was sent', async () => {
        const onUnauthorized = jest.fn();
        const client = createClient({ baseUrl: 'https://x.test', getToken: () => null, onUnauthorized, fetchImpl: reply(401, {}) });
        await client.get('/app/me');
        expect(onUnauthorized).not.toHaveBeenCalled();
    });

    it('says offline when the request throws', async () => {
        const client = createClient({ baseUrl: 'https://x.test', getToken: () => null, fetchImpl: (async () => { throw new TypeError('Network request failed'); }) as typeof fetch });
        const result = await client.get('/app/catalog');
        expect(result.kind).toBe('offline');
        expect(problemText(result)).toMatch(/No connection/);
    });

    it('shows the first validation message', () => {
        expect(problemText({ kind: 'invalid', errors: { phone: ['Enter your mobile number'] }, message: null })).toBe('Enter your mobile number');
    });
});

describe('mock adapter (same shapes as the contract)', () => {
    const make = (token: string | null) => createApi(createClient({ baseUrl: 'https://x.test', getToken: () => token, fetchImpl: createMockFetch() }));

    it('rejects calls without the token', async () => {
        expect((await make(null).home()).kind).toBe('signIn');
    });

    it('signs in with the demo account and reads home', async () => {
        const signed = await make(null).signInDemo('test');
        expect(signed.kind).toBe('ok');
        const home = await make('mock-token').home();
        expect(home.kind === 'ok' && home.data.inShop?.stage).toBe('in_progress');
        expect(home.kind === 'ok' && home.data.garage.length).toBe(3);
    });

    it('serves the catalog without a token, with the sign-in flags', async () => {
        const catalog = await make(null).catalog();
        expect(catalog.kind === 'ok' && Object.keys(catalog.data.signIn)).toEqual(['google', 'apple', 'demo']);
    });

    it('keeps other things 404', async () => {
        expect((await make('mock-token').vehicle(999)).kind).toBe('notFound');
    });

    it('reserves a booking', async () => {
        const result = await make('mock-token').reserve({ branch: 1, services: [11], date: '2030-01-01', vehicle_id: 1, name: 'A', phone: '09171234567', consent: true });
        expect(result.kind === 'ok' && result.data.booking?.stage).toBe('confirmed');
    });
});
