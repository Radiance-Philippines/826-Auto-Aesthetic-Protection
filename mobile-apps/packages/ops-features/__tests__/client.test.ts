import { createClient, problemText, query, sortResponse } from '@826/core';
import { createApi } from '../src/api/endpoints';
import { createMockFetch, setMockRole } from '../src/api/mock';

const reply = (status: number, body: unknown) => jest.fn(async () => ({ status, json: async () => body }) as Response) as unknown as typeof fetch;

describe('sortResponse', () => {
    it('sorts statuses by what a screen does next', () => {
        expect(sortResponse(200, { a: 1 })).toEqual({ kind: 'ok', data: { a: 1 } });
        expect(sortResponse(401, { signIn: true }).kind).toBe('signIn');
        expect(sortResponse(422, { errors: { code: ['Bad code'] }, message: 'bad' })).toEqual({ kind: 'invalid', errors: { code: ['Bad code'] }, message: 'bad' });
        expect(sortResponse(409, { message: 'Held' }).kind).toBe('conflict');
        expect(sortResponse(404, null).kind).toBe('notFound');
        expect(sortResponse(429, null).kind).toBe('tooMany');
        expect(sortResponse(500, null).kind).toBe('failed');
    });

    it('keeps the plain message of a 403 (the 2FA setup instruction must reach the screen)', () => {
        const result = sortResponse(403, { message: 'Set up two-step sign-in on the website first.' }, 'team');
        expect(result).toEqual({ kind: 'forbidden', message: 'Set up two-step sign-in on the website first.' });
        expect(problemText(result)).toBe('Set up two-step sign-in on the website first.');
    });
});

describe('query', () => {
    it('leaves empty values out', () => {
        expect(query({ branch: 1, q: '', page: undefined, date: '2026-11-21' })).toBe('?branch=1&date=2026-11-21');
        expect(query({})).toBe('');
    });
});

describe('client', () => {
    it('sends the bearer token and JSON', async () => {
        const fetchImpl = reply(200, { ok: true });
        await createClient({ baseUrl: 'https://x.test/demo/826', getToken: () => 'tok', fetchImpl }).post('/staff/sign-out');
        const [url, init] = (fetchImpl as unknown as jest.Mock).mock.calls[0];
        expect(url).toBe('https://x.test/demo/826/api/staff/sign-out');
        expect(init.headers).toMatchObject({ Authorization: 'Bearer tok', Accept: 'application/json', 'Content-Type': 'application/json' });
    });

    it('sends no token on sign-in, and no JSON type on uploads', async () => {
        const fetchImpl = reply(200, {});
        const client = createClient({ baseUrl: 'https://x.test', getToken: () => 'tok', fetchImpl });
        await client.post('/staff/sign-in', {}, { auth: false });
        await client.upload('/staff/bookings/1/photos', new FormData());
        const calls = (fetchImpl as unknown as jest.Mock).mock.calls;
        expect(calls[0][1].headers.Authorization).toBeUndefined();
        expect(calls[1][1].headers['Content-Type']).toBeUndefined();
        expect(calls[1][1].headers.Authorization).toBe('Bearer tok');
    });

    it('calls onUnauthorized on a 401 with a token, not without one', async () => {
        const onUnauthorized = jest.fn();
        await createClient({ baseUrl: 'https://x.test', getToken: () => 'old', onUnauthorized, fetchImpl: reply(401, { message: 'Sign in to continue.', signIn: true }) }).get('/staff/me');
        expect(onUnauthorized).toHaveBeenCalledTimes(1);
        await createClient({ baseUrl: 'https://x.test', getToken: () => null, onUnauthorized, fetchImpl: reply(401, {}) }).get('/staff/me');
        expect(onUnauthorized).toHaveBeenCalledTimes(1);
    });

    it('says offline when the request fails', async () => {
        const fetchImpl = jest.fn(async () => {
            throw new Error('network');
        }) as unknown as typeof fetch;
        const result = await createClient({ baseUrl: 'https://x.test', getToken: () => null, fetchImpl }).get('/staff/me');
        expect(result.kind).toBe('offline');
        expect(problemText(result)).toContain('No connection');
    });
});

describe('endpoints against the fake API', () => {
    const fetchImpl = jest.fn(createMockFetch());
    const api = createApi(createClient({ baseUrl: 'https://x.test', getToken: () => 'mock-token', fetchImpl: fetchImpl as unknown as typeof fetch }));

    it('builds the contract paths and bodies', async () => {
        setMockRole('branch_manager');
        await api.queue(1, '2026-11-21');
        await api.setStage(3, 'exited');
        await api.setReadyDate(3, '2026-11-24');
        await api.addNote(3, 'Wiped the door');
        await api.setSize(11, 2);
        await api.rejectPayment(12, 'Wrong amount');
        await api.customers('miguel', 2);
        await api.inbox('all', 'open', '');
        await api.overview('7d', 2);
        await api.setDone(7, true);
        const calls = fetchImpl.mock.calls.map(([url, init]) => `${init?.method ?? 'GET'} ${String(url).replace('https://x.test/api', '')} ${init?.body ?? ''}`);
        expect(calls).toEqual([
            'GET /staff/queue?branch=1&date=2026-11-21 ',
            'POST /staff/bookings/3/stage {"to":"exited"}',
            'POST /staff/bookings/3/ready-date {"date":"2026-11-24"}',
            'POST /staff/bookings/3/notes {"body":"Wiped the door"}',
            'POST /staff/bookings/11/size {"vehicle_size_id":2}',
            'POST /staff/bookings/12/payment/reject {"reason":"Wrong amount"}',
            'GET /staff/customers?q=miguel&page=2 ',
            'GET /staff/inbox?branch=all&filter=open ',
            'GET /staff/overview?period=7d&branch=2 ',
            'POST /staff/inbox/7/done {"done":true}',
        ]);
    });

    it('answers 401 without the token', async () => {
        const open = createApi(createClient({ baseUrl: 'https://x.test', getToken: () => null, fetchImpl: createMockFetch() }));
        expect((await open.me()).kind).toBe('signIn');
    });
});
