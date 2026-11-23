/**
 * A small fake of the 826 staff API, shaped like src/api/types.ts. It is the
 * `fetch` that jest and EXPO_PUBLIC_MOCK=1 hand to the client, so every
 * screen can be built and tested before the backend answers. The people,
 * cars and numbers here are sample data and never ship in a normal build.
 * The signed-in role follows the email: ops@, sales@, manager@, owner@.
 */
import { addDays, isoDate, manilaToday, parseDate } from '@/lib/dates';
import type { Branch, Card, ConversationRow, CustomerDetail, Inbox, Message, NextStep, Overview, Stage, StaffUser, Today, UnitDetail, Vehicle } from './types';

type Json = Record<string, unknown> | unknown[];

const TOKEN = 'mock-token';
const iso = (offset: number) => isoDate(addDays(parseDate(manilaToday()), offset));
const at = (offset: number, time: string) => `${iso(offset)}T${time}+08:00`;

const cainta: Branch = { id: 1, name: 'Cainta' };
const quezon: Branch = { id: 2, name: 'Quezon City' };

export type MockRole = 'operations' | 'sales_admin' | 'branch_manager' | 'super_admin';

const USERS: Record<MockRole, StaffUser> = {
    operations: { id: 1, name: 'Jun Reyes', email: 'ops@example.test', role: 'operations', roleLabel: 'Head detailer', isOwner: false, areas: ['queue.update', 'bookings.view'], branches: [cainta], tabs: ['queue', 'me'] },
    sales_admin: { id: 2, name: 'Rhea Cruz', email: 'sales@example.test', role: 'sales_admin', roleLabel: 'Sales rep', isOwner: false, areas: ['bookings.view', 'bookings.sales', 'customers.view', 'customers.write', 'inbox'], branches: [cainta], tabs: ['today', 'clients', 'inbox', 'me'] },
    branch_manager: { id: 3, name: 'Mark Dela Cruz', email: 'manager@example.test', role: 'branch_manager', roleLabel: 'Branch manager', isOwner: false, areas: ['queue.update', 'bookings.view', 'bookings.sales', 'customers.view', 'customers.write', 'inbox', 'dashboard'], branches: [cainta], tabs: ['queue', 'today', 'clients', 'inbox', 'me'] },
    super_admin: { id: 4, name: 'Owner', email: 'owner@example.test', role: 'super_admin', roleLabel: 'Owner', isOwner: true, areas: ['queue.update', 'bookings.view', 'bookings.sales', 'customers.view', 'customers.write', 'inbox', 'dashboard'], branches: [cainta, quezon], tabs: ['queue', 'today', 'inbox', 'overview', 'me'], overflow: ['clients'] },
};

let role: MockRole = 'operations';
/** Jest picks the signed-in role directly */
export const setMockRole = (next: MockRole) => {
    role = next;
};
export const mockUser = (next: MockRole): StaffUser => USERS[next];

const car = (year: number, model: string, typeLabel: string, plate: string): Vehicle => ({ id: null, year, model, type: typeLabel.toLowerCase(), typeLabel, plate, size: null });

const card = (id: number, stage: Stage, vehicle: Vehicle, services: string[], extra: Partial<Card> = {}): Card => ({
    id,
    reference: `826-${id}`,
    kind: 'onsite',
    stage,
    name: 'Miguel',
    unit: `${vehicle.year} ${vehicle.model}`,
    vehicle,
    services,
    servicesLabel: services.join(', '),
    dropOffOn: iso(0),
    readyOn: iso(3),
    readyLabel: null,
    dayNumber: 1,
    totalDays: 3,
    dayLabel: 'Day 1 of 3',
    home: false,
    needsSize: false,
    late: false,
    note: null,
    next: null,
    canAdvance: false,
    arrivedAt: null,
    ...extra,
});

const NEXT: Partial<Record<Stage, NextStep>> = {
    confirmed: { to: 'arrived', label: 'Mark arrived', confirm: false },
    arrived: { to: 'in_progress', label: 'Start work', confirm: false },
    in_progress: { to: 'quality_check', label: 'Send to quality check', confirm: false },
    quality_check: { to: 'ready', label: 'Mark ready', confirm: false },
    ready: { to: 'exited', label: 'Mark as exited', confirm: true },
};
const LABELS: Record<string, string> = { confirmed: 'Booked', arrived: 'Arrived', in_progress: 'In progress', quality_check: 'Quality check', ready: 'Ready', exited: 'Exited' };
const ORDER = Object.keys(LABELS);

export function createMockFetch(): typeof fetch {
    const units: Card[] = [
        card(1, 'in_progress', car(2023, 'BMW 7 Series', 'Sedan', 'NDA 4821'), ['Graphene coating'], { dayLabel: 'Day 1 of 3' }),
        card(2, 'arrived', car(2022, 'Toyota Land Cruiser 300', 'SUV', 'NEB 1190'), ['PPF full body'], { totalDays: 5, dayLabel: 'Day 1 of 5', name: 'Carla' }),
        card(3, 'ready', car(2024, 'Porsche 911 Carrera', 'Coupe', 'NFT 911'), ['Nano ceramic tint'], { dayNumber: null, totalDays: null, dayLabel: null }),
    ];
    const withNext = (unit: Card): Card => ({ ...unit, next: NEXT[unit.stage] ?? null, canAdvance: role === 'operations' || role === 'branch_manager' || role === 'super_admin' });
    const notes: UnitDetail['history'] = [{ id: 1, kind: 'status', from: 'Booked', to: 'Arrived', note: null, by: 'Jun Reyes', at: at(0, '09:12:00'), line: 'Nov 21, 9:12 AM · Jun Reyes: Status: Booked → Arrived' }];
    const photos: UnitDetail['photos'] = [];
    const today = (): Today => ({
        branch: cainta,
        date: iso(0),
        dropOffsToday: [card(10, 'confirmed', car(2022, 'Ford Mustang Dark Horse', 'Coupe', 'PDZ 220'), ['Exterior detailing'], { name: 'Paolo' })],
        needsSizeReview: [card(11, 'confirmed', car(2021, 'Range Rover', 'SUV', 'CMX 2021'), ['Nano ceramic tint'], { name: 'Carla', needsSize: true })],
        paymentsToCheck: [{ ...card(12, 'payment_review', car(2023, 'Mercedes-AMG G 63', 'SUV', 'JCZ 630'), ['PPF full body'], { name: 'Joaquin' }), payment: { method: 'gcash', amountDue: 500, submittedAt: at(0, '08:00:00'), proofUrl: 'https://example.test/api/staff/bookings/12/proof' } }],
        homeRequests: [{ ...card(13, 'requested', car(2020, 'Honda Civic', 'Sedan', 'ABC 123'), ['Interior detailing'], { name: 'Ana', kind: 'home', home: true }), preferredDate: iso(2), preferredWindow: 'morning', address: 'Sample street', area: 'Cainta' }],
    });
    let customer: CustomerDetail = {
        id: 5,
        name: 'Miguel Santos',
        phone: '0917 123 4567',
        email: null,
        branch: cainta,
        source: 'website',
        sourceLabel: 'Website',
        referralCode: 'MIGUEL-826',
        pointsBalance: 300,
        notes: null,
        units: [{ id: 1, label: '2023 BMW 7 Series (Sedan)', plate: 'NDA 4821', size: null, historyCount: 2 }],
        bookings: [{ id: 1, reference: '826-1', status: 'in_progress', statusLabel: 'In progress', branch: 'Cainta', unit: '2023 BMW 7 Series', servicesLabel: 'Graphene coating', dropOffOn: iso(0), readyOn: iso(3), total: role === 'operations' ? null : 48000 }],
        messenger: { conversationId: 7, branchId: 1 },
        can: { edit: true },
    };
    const row = (): ConversationRow => ({ id: 7, name: 'Miguel Santos', avatar: null, branch: cainta, preview: messages.at(-1)?.body ?? null, lastMessageAt: messages.at(-1)?.sentAt ?? null, unread: done ? 0 : 1, done, sample: true, linkedCustomer: true });
    let done = false;
    const messages: Message[] = [{ id: 1, direction: 'in', body: 'Hi! Can I bring the Land Cruiser in for PPF this week?', attachments: [], senderName: 'Miguel Santos', failed: false, error: null, sentAt: at(0, '10:02:00') }];
    const inbox = (): Inbox => ({
        branch: role === 'super_admin' ? 'all' : '1',
        filter: 'open',
        showsAll: role === 'super_admin',
        tabs: [...(role === 'super_admin' ? [{ key: 'all', label: 'All', unread: 1 }] : []), { key: '1', label: 'Cainta', unread: 1 }, ...(role === 'super_admin' ? [{ key: '2', label: 'Quezon City', unread: 0 }] : [])],
        totalUnread: 1,
        notice: null,
        conversations: [row()],
    });
    const detail = (id: number): UnitDetail | null => {
        const found = units.find((u) => u.id === id);
        if (!found) return null;
        const sales = role === 'sales_admin' || role === 'branch_manager' || role === 'super_admin';
        const contact = role !== 'operations';
        const next = withNext(found);
        const at0 = ORDER.indexOf(found.stage);

        return {
            id,
            reference: found.reference,
            kind: 'onsite',
            status: found.stage,
            stage: found.stage,
            statusLabel: LABELS[found.stage] ?? found.stage,
            branch: cainta,
            customer: { firstName: found.name, name: contact ? 'Miguel Santos' : null, phone: contact ? '0917 123 4567' : null, email: null, guestId: contact ? 5 : null, address: null, area: null },
            customerMessage: null,
            vehicle: { ...found.vehicle, label: found.unit },
            services: found.services.map((name) => ({ name, category: null, estimateDays: found.totalDays })),
            dropOffOn: found.dropOffOn,
            readyOn: found.readyOn,
            readyLabel: null,
            dayNumber: found.dayNumber,
            totalDays: found.totalDays,
            dayLabel: found.dayLabel,
            late: false,
            needsSize: false,
            next: next.next,
            can: { advance: next.canAdvance && next.next !== null, changeReadyDate: role !== 'sales_admin', addNote: true, addPhoto: role !== 'sales_admin', setSize: sales, reviewPayment: false },
            timeline: ORDER.map((key, index) => ({ key, label: LABELS[key], at: index <= Math.min(at0, 1) ? at(index === 0 ? -2 : 0, '09:12:00') : null, done: index <= at0, current: index === at0 })),
            photos,
            history: [...notes].reverse(),
            money: sales ? { priced: true, total: 48000, subtotal: 48000, discount: null, amountDue: 500, amountPaid: 500, paidAt: null, paymentMethod: 'gcash', paymentSubmittedAt: null, rejectedReason: null, proofUrl: null } : null,
        };
    };
    const overview = (url: URL): Overview => {
        const manager = role === 'branch_manager';

        return {
            period: { key: url.searchParams.get('period') ?? '7d', label: 'Last 7 days', from: iso(-6), to: iso(0) },
            branch: null,
            branches: role === 'super_admin' ? [cainta, quezon] : [cainta],
            sales: 412800,
            collected: 300000,
            averageTicket: 18763,
            unitsDone: 22,
            turnaround: { toReady: 2.4, toExit: 3.1 },
            branchesTable: role === 'super_admin' ? [{ name: 'Cainta', units: 8, sales: 168200, average: 21025, noShowRate: null, toReady: 2.1, toExit: 2.8 }, { name: 'Quezon City', units: 9, sales: 142500, average: 15833, noShowRate: null, toReady: 2.6, toExit: 3.2 }] : null,
            topReps: role === 'super_admin' || manager ? [{ name: 'Joanna Cruz', role: 'Sales rep', jobs: 12, sales: 168000, moves: 0, referrals: 1 }, { name: 'Mark Reyes', role: 'Sales rep', jobs: 9, sales: 142000, moves: 0, referrals: 0 }] : null,
            backjobsOpen: 2,
            reviewRating: { rating: 4.8, count: 31 },
            attention: { size: 1, screenshots: 1, home: 1, reviews: null, chats: 1, late: 0 },
        };
    };

    return (async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input));
        const path = url.pathname.replace(/^.*\/api/, '');
        const method = (init?.method ?? 'GET').toUpperCase();
        const headers = (init?.headers ?? {}) as Record<string, string>;
        const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, string>) : {};
        const reply = (status: number, json: Json = {}) => ({ status, ok: status < 300, json: async () => json }) as Response;
        const publicPath = path === '/staff/latest' || path.startsWith('/staff/sign-in');

        await new Promise((resolve) => setTimeout(resolve, process.env.NODE_ENV === 'test' ? 0 : 250));

        if (!publicPath && headers.Authorization !== `Bearer ${TOKEN}`) {
            return reply(401, { message: 'Sign in to continue.', signIn: true });
        }

        if (path === '/staff/latest') return reply(200, { version: null, versionCode: 0, url: null, sha256: null, notes: null, mandatory: false });
        if (path === '/staff/sign-in/two-factor') return body.code === '123456' ? reply(200, { token: TOKEN, user: USERS[role] }) : reply(422, { message: 'That code isn’t right. Check the app and try again.', errors: { code: ['That code isn’t right. Check the app and try again.'] } });
        if (path === '/staff/sign-in') {
            const email = String(body.email ?? '');
            const picked = (Object.keys(USERS) as MockRole[]).find((key) => USERS[key].email === email);
            if (!picked || body.password !== 'sample-password') return reply(422, { message: 'Those details don’t match. Check your email and password.', errors: { email: ['Those details don’t match. Check your email and password.'] } });
            role = picked;

            return picked === 'super_admin' ? reply(200, { twoFactor: { challenge: 'sample-challenge', expiresInSeconds: 300 } }) : reply(200, { token: TOKEN, expiresAt: at(30, '00:00:00'), user: USERS[picked] });
        }
        if (path === '/staff/sign-out') return reply(200, { ok: true });
        if (path === '/staff/me') return reply(200, USERS[role]);
        if (path === '/staff/queue') {
            const cards = units.map(withNext);
            const columns = ORDER.slice(0, 5).map((key) => ({ key, label: LABELS[key], count: cards.filter((u) => u.stage === key).length, cards: cards.filter((u) => u.stage === key) }));

            return reply(200, { branch: cainta, branches: USERS[role].branches, date: url.searchParams.get('date') ?? iso(0), heading: 'Saturday, Nov 21, 2026', isToday: true, dropOffs: { cap: 6, booked: 3, state: 'open' }, columns, counts: { all: cards.length }, needsSize: null, homeRequests: null });
        }
        if (path === '/staff/today') return reply(200, today());
        if (path === '/staff/vehicle-sizes') return reply(200, [{ id: 1, name: 'Small', examples: 'Hatchback' }, { id: 2, name: 'Medium', examples: 'Sedan' }, { id: 3, name: 'Large', examples: 'SUV' }]);
        if (path === '/staff/customers' && method === 'GET') return reply(200, { data: [{ id: 5, name: 'Miguel Santos', phone: '0917 123 4567', email: null, branch: 'Cainta', unitsCount: 1, bookingsCount: 1, lastVisitOn: iso(-3) }], page: 1, perPage: 20, total: 1, hasMore: false });
        if (path === '/staff/inbox') return reply(200, inbox());
        if (path === '/staff/inbox/quick-replies') return reply(200, { branch: cainta, replies: [{ key: 'slot_confirmed', label: 'Slot confirmed', text: 'Hi Miguel, your slot at 826 Cainta is confirmed. See you!' }] });
        if (path === '/staff/overview') return reply(200, overview(url));

        const one = path.match(/^\/staff\/bookings\/(\d+)(?:\/(stage|ready-date|notes|photos|size|payment\/approve|payment\/reject))?$/);

        if (one) {
            const id = Number(one[1]);
            const found = units.find((u) => u.id === id);
            if (!one[2]) return detail(id) ? reply(200, detail(id)!) : reply(404);
            if (one[2] === 'stage' && found) {
                const to = body.to as Stage;
                if (NEXT[found.stage]?.to !== to) return reply(422, { message: 'This unit’s next step is different.', errors: { to: ['This unit’s next step is different.'] } });
                found.stage = to;
            }
            if (one[2] === 'ready-date' && found) found.readyOn = body.date;
            if (one[2] === 'notes') notes.push({ id: notes.length + 1, kind: 'note', from: null, to: null, note: body.body, by: USERS[role].name, at: new Date().toISOString(), line: `Note: ${body.body}` });
            if (one[2] === 'photos') photos.push({ id: photos.length + 1, url: 'https://example.test/p.jpg', thumb: 'https://example.test/p-thumb.jpg', at: new Date().toISOString(), by: USERS[role].name });

            return found ? reply(one[2] === 'notes' || one[2] === 'photos' ? 201 : 200, detail(id)!) : reply(200, { ...detail(1)!, missingPrices: [] });
        }

        const cust = path.match(/^\/staff\/customers\/(\d+)$/);
        if (cust) {
            if (method === 'PATCH') customer = { ...customer, ...body };

            return reply(200, customer);
        }

        const conv = path.match(/^\/staff\/inbox\/(\d+)(?:\/(reply|done))?$/);
        if (conv) {
            if (conv[2] === 'reply') {
                const message: Message = { id: messages.length + 1, direction: 'out', body: body.body, attachments: [], senderName: USERS[role].name, failed: false, error: null, sentAt: new Date().toISOString() };
                messages.push(message);

                return reply(201, { message, conversation: row(), savedOnly: true });
            }
            if (conv[2] === 'done') {
                done = Boolean(body.done);

                return reply(200, row());
            }

            return reply(200, { conversation: { ...row(), assignedTo: null, customer: { id: 5, name: 'Miguel Santos' }, pastReplyWindow: false, windowHours: 24, savesLocallyOnly: true }, messages, hasOlder: false });
        }

        return reply(404);
    }) as typeof fetch;
}
