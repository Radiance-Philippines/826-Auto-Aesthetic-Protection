/**
 * A small fake of the 826 API, shaped like src/api/types.ts. It is the
 * `fetch` that jest and EXPO_PUBLIC_MOCK=1 hand to the client, so every
 * screen can be built and screenshotted before the backend answers.
 * The people, cars and numbers here are sample data and never ship in a
 * normal build (the mock is only used when MOCK is on).
 */
import { addDays, isoDate, manilaToday, parseDate } from '@/lib/dates';
import type { Catalog, CustomerBooking, DayState, Home, MonthAvailability, Rewards, Showcase, SavedVehicle, WarrantyUnit } from './types';

type Json = Record<string, unknown> | unknown[];

const TOKEN = 'mock-token';
const iso = (offset: number) => isoDate(addDays(parseDate(manilaToday()), offset));
const at = (offset: number, time: string) => `${iso(offset)}T${time}+08:00`;

const guest = { id: 1, name: 'Miguel Santos', email: 'miguel@example.test', phone: '0917 123 4567', avatar: null, referralCode: 'MIGUEL-826', source: 'google' };

const sample = (n: number) => ({ src: `https://picsum.photos/seed/826-${n}/800/600`, srcset: null, thumb: `https://picsum.photos/seed/826-${n}/200/150` });

/** Sample Our work content: the real thing comes from the admin's Videos, Photos and approved Reviews */
const showcase: Showcase = {
    videos: [
        { id: 1, title: 'Ceramic coating, start to finish', caption: null, youtubeId: 'AAAAAAAAAAA', thumbnail: 'https://img.youtube.com/vi/AAAAAAAAAAA/hqdefault.jpg', url: 'https://www.youtube.com/watch?v=AAAAAAAAAAA' },
        { id: 2, title: 'Paint protection film on a sedan', caption: 'Sample caption', youtubeId: 'BBBBBBBBBBB', thumbnail: 'https://img.youtube.com/vi/BBBBBBBBBBB/hqdefault.jpg', url: 'https://www.youtube.com/watch?v=BBBBBBBBBBB' },
    ],
    photos: [
        { id: 1, alt: '826: coating', category: 'coating', categoryLabel: 'Coating', image: sample(1) },
        { id: 2, alt: '826: paint protection film', category: 'ppf', categoryLabel: 'Paint protection film', image: sample(2) },
        { id: 3, alt: '826: window tint', category: 'tint', categoryLabel: 'Window tint', image: sample(3) },
        { id: 4, alt: '826: the shops', category: 'shop', categoryLabel: 'The shops', image: sample(4) },
    ],
    reviews: [
        { id: 1, name: 'Carlo M.', rating: 5, body: 'Sample review text. The car looks new again.', photo: null, service: 'Ceramic coating', branch: 'Cainta', date: '2026-11-02' },
        { id: 2, name: 'Ana R.', rating: 4, body: 'Sample review text. Clean work, on time.', photo: sample(5), service: null, branch: null, date: '2026-10-20' },
    ],
};

const catalog: Catalog = {
    signIn: { google: true, apple: true, demo: true },
    vehicleTypes: [
        { key: 'sedan', label: 'Sedan' },
        { key: 'hatchback', label: 'Hatchback' },
        { key: 'coupe', label: 'Coupe' },
        { key: 'suv', label: 'SUV' },
        { key: 'pickup', label: 'Pickup' },
        { key: 'van', label: 'Van' },
        { key: 'motorcycle', label: 'Motorcycle' },
    ],
    booking: {
        cancelCutoffHours: 24,
        rescheduleEnabled: true,
        rescheduleCutoffHours: 24,
        maxReschedules: 1,
        policy: 'Sample booking policy.',
        fee: { enabled: true, label: 'a small fee', holdMinutes: 30 },
        windows: { morning: 'Morning', afternoon: 'Afternoon', any: 'Any time' },
        referral: { enabled: true, discountLabel: 'a discount' },
    },
    categories: [
        {
            id: 1,
            name: 'Detailing',
            slug: 'detailing',
            services: [
                { id: 11, name: 'Exterior detailing', slug: 'exterior-detailing', summary: null, homeServiceOk: true, estimateDays: 2 },
                { id: 12, name: 'Interior detailing', slug: 'interior-detailing', summary: null, homeServiceOk: true, estimateDays: 1 },
            ],
        },
        {
            id: 2,
            name: 'Coating',
            slug: 'coating',
            services: [{ id: 21, name: 'Graphene coating', slug: 'graphene-coating', summary: null, homeServiceOk: false, estimateDays: 3 }],
        },
        {
            id: 3,
            name: 'Paint protection film',
            slug: 'paint-protection-film',
            services: [{ id: 31, name: 'PPF full body', slug: 'ppf-full-body', summary: null, homeServiceOk: false, estimateDays: 5 }],
        },
    ],
    branches: [
        {
            id: 1,
            slug: 'cainta',
            name: 'Cainta',
            status: 'open',
            city: 'Cainta, Rizal',
            address: 'Sample address, Cainta',
            phone: '0948 804 2284',
            phoneHref: '+639488042284',
            hours: [1, 2, 3, 4, 5, 6].map((day) => ({ day, label: '', open: '08:00', close: '18:00' })).concat([{ day: 7, label: '', open: null as unknown as string, close: null as unknown as string }]),
            lastDay: iso(120),
            bookable: true,
            serviceIds: [11, 12, 21, 31],
            homeService: { offered: true, areas: ['Cainta', 'Taytay', 'Marikina'] },
        },
        {
            id: 2,
            slug: 'quezon-city',
            name: 'Quezon City',
            status: 'open',
            city: 'Quezon City',
            address: null,
            phone: null,
            phoneHref: null,
            hours: [],
            lastDay: iso(120),
            bookable: true,
            serviceIds: [11, 21],
            homeService: { offered: false, areas: [] },
        },
    ],
};

const vehicles: SavedVehicle[] = [
    { id: 1, year: 2023, model: 'BMW 7 Series', type: 'sedan', plate: 'NDA 4821', sizeReviewed: true, lastService: { name: 'Exterior detailing', on: iso(-70) } },
    { id: 2, year: 2022, model: 'Toyota Land Cruiser 300', type: 'suv', plate: 'NEB 1190', sizeReviewed: true, lastService: { name: 'PPF full body', on: iso(-260) } },
    { id: 3, year: 2024, model: 'Porsche 911 Carrera', type: 'coupe', plate: 'NFT 911', sizeReviewed: false, lastService: null },
];

const branchRef = { id: 1, name: 'Cainta', slug: 'cainta', address: 'Sample address, Cainta', phone: '0948 804 2284', phoneHref: '+639488042284', messenger: null };

function booking(id: number, stage: CustomerBooking['stage'], vehicleIndex: number, extra: Partial<CustomerBooking> = {}): CustomerBooking {
    const v = vehicles[vehicleIndex];

    return {
        id,
        reference: `826-MOCK${id}`,
        url: `/bookings/${id}`,
        kind: 'onsite',
        status: stage,
        stage,
        branch: branchRef,
        dropOffOn: iso(-2),
        readyOn: iso(1),
        estimate: { days: 3, label: 'about 3 days' },
        when: '',
        arrivedAt: at(-2, '09:12:00'),
        exitedAt: null,
        preferredDate: null,
        preferredWindow: null,
        vehicle: { id: v.id, year: v.year, model: v.model, type: v.type, typeLabel: v.type === 'suv' ? 'SUV' : v.type[0].toUpperCase() + v.type.slice(1), plate: v.plate, size: null, sizeReviewed: v.sizeReviewed },
        items: [{ name: 'Graphene coating', category: 'Coating', estimateDays: 3 }],
        price: { status: 'reviewing', total: null, subtotal: null, discount: null, items: [] },
        address: null,
        area: null,
        amountDue: null,
        balance: null,
        holdExpiresAt: null,
        payment: { method: null, submittedAt: null, rejectedReason: null, proofUrl: null, paidAt: null },
        methods: [],
        instructions: null,
        canCancel: false,
        canReschedule: false,
        canReview: false,
        review: null,
        createdAt: at(-5, '15:20:00'),
        ...extra,
    };
}

export function createMockFetch(): typeof fetch {
    let guestState = { ...guest };
    const bookings: CustomerBooking[] = [
        booking(1, 'in_progress', 0),
        booking(2, 'exited', 1, { dropOffOn: iso(-260), readyOn: iso(-255), arrivedAt: at(-260, '09:00:00'), exitedAt: at(-255, '16:00:00'), canReview: true, items: [{ name: 'PPF full body', category: 'Paint protection film', estimateDays: 5 }] }),
        booking(3, 'awaiting_payment', 2, {
            dropOffOn: iso(9),
            readyOn: iso(12),
            arrivedAt: null,
            amountDue: 500,
            holdExpiresAt: new Date(Date.now() + 25 * 60000).toISOString(),
            canCancel: true,
            canReschedule: true,
            methods: [
                { key: 'gcash', label: 'GCash', qr: null, accountName: 'Sample Account', accountNumber: '0917 000 0000' },
                { key: 'maya', label: 'Maya', qr: null, accountName: 'Sample Account', accountNumber: '0917 000 0001' },
            ],
            items: [{ name: 'Exterior detailing', category: 'Detailing', estimateDays: 2 }],
        }),
    ];

    const home = (): Home => ({
        guest: guestState,
        inShop: bookings.find((b) => ['arrived', 'in_progress', 'quality_check', 'ready'].includes(b.stage)) ?? null,
        nextBooking: bookings.find((b) => ['confirmed', 'awaiting_payment', 'payment_review'].includes(b.stage)) ?? null,
        referral: { enabled: true, code: 'MIGUEL-826', discountLabel: 'a discount' },
        garage: vehicles,
        unread: 0,
    });

    const rewards: Rewards = {
        enabled: true,
        code: 'MIGUEL-826',
        discountLabel: 'a discount',
        pointsBalance: 300,
        pointsEarned: 300,
        pointsSpent: 0,
        pesoPerPoint: null,
        friends: [
            { name: 'Andrea R.', usedOn: iso(-20), status: 'awarded', points: 100 },
            { name: 'Paolo D.', usedOn: iso(-14), status: 'awarded', points: 100 },
            { name: 'Carla M.', usedOn: iso(-6), status: 'awarded', points: 100 },
        ],
        ledger: [
            { date: iso(-20), kind: 'earned', points: 100, note: 'A friend booked with your code' },
            { date: iso(-14), kind: 'earned', points: 100, note: 'A friend booked with your code' },
            { date: iso(-6), kind: 'earned', points: 100, note: 'A friend booked with your code' },
        ],
    };

    const warranty: WarrantyUnit[] = [
        {
            vehicleId: 1,
            vehicleLabel: '2023 BMW 7 Series',
            services: [
                { name: 'Exterior detailing', finishedOn: iso(-70), endsOn: iso(110), status: 'active', months: 6, backjobTypesCovered: ['Swirl marks', 'Water spots'] },
                { name: 'PPF windshield', finishedOn: iso(-500), endsOn: iso(-140), status: 'expired', months: 12, backjobTypesCovered: [] },
            ],
        },
        { vehicleId: 2, vehicleLabel: '2022 Toyota Land Cruiser 300', services: [{ name: 'PPF full body', finishedOn: iso(-255), endsOn: iso(110), status: 'active', months: 12, backjobTypesCovered: ['Bubbling', 'Peeling edges'] }] },
    ];

    function availability(url: URL): MonthAvailability {
        const month = url.searchParams.get('month') ?? manilaToday().slice(0, 7);
        const first = iso(1);
        const last = iso(120);
        const [year, mon] = month.split('-').map(Number);
        const days: Record<string, DayState> = {};

        for (let d = 1; d <= new Date(year, mon, 0).getDate(); d++) {
            const day = `${month}-${String(d).padStart(2, '0')}`;
            const weekday = parseDate(day).getDay();
            days[day] = day < first || day > last ? 'closed' : weekday === 0 ? 'closed' : d % 9 === 0 ? 'full' : 'open';
        }

        return { month, first, last, days, estimate: { days: 3, label: 'about 3 days' } };
    }

    return (async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input));
        const path = url.pathname.replace(/^.*\/api/, '');
        const method = (init?.method ?? 'GET').toUpperCase();
        const headers = (init?.headers ?? {}) as Record<string, string>;
        const reply = (status: number, body: Json = {}) => ({ status, ok: status < 300, json: async () => body }) as Response;
        const publicPath = path === '/app/catalog' || path === '/app/showcase' || path === '/app/latest' || path.startsWith('/app/sign-in');

        await new Promise((resolve) => setTimeout(resolve, process.env.NODE_ENV === 'test' ? 0 : 250));

        if (!publicPath && headers.Authorization !== `Bearer ${TOKEN}`) {
            return reply(401, { message: 'Please sign in.' });
        }

        if (path === '/app/catalog') return reply(200, catalog);
        if (path === '/app/showcase') return reply(200, showcase);
        if (path === '/app/latest') return reply(200, {});
        if (path.startsWith('/app/sign-in')) return reply(200, { token: TOKEN, guest: guestState });
        if (path === '/app/sign-out' || path === '/app/account/delete' || path === '/app/push-token') return reply(200, { ok: true });
        if (path === '/app/me' && method === 'PATCH') {
            guestState = { ...guestState, ...JSON.parse(String(init?.body ?? '{}')) };

            return reply(200, guestState);
        }
        if (path === '/app/me') return reply(200, guestState);
        if (path === '/app/home') return reply(200, home());
        if (path === '/my-rewards') return reply(200, rewards);
        if (path === '/my-vehicles') return reply(200, { vehicles });
        if (path === '/my-warranty') return reply(200, warranty);
        if (path === '/availability') return reply(200, availability(url));
        if (path === '/my-bookings') return reply(200, { bookings });

        const vehicle = path.match(/^\/my-vehicles\/(\d+)$/);

        if (vehicle) {
            const found = vehicles.find((v) => v.id === Number(vehicle[1]));

            return found
                ? reply(200, { vehicle: found, history: bookings.filter((b) => b.vehicle.id === found.id).map((b) => ({ reference: b.reference, date: b.dropOffOn, branch: 'Cainta', services: b.items.map((i) => i.name), status: b.stage, price: null, id: b.id })) })
                : reply(404);
        }

        const one = path.match(/^\/my-bookings\/(\d+)(?:\/(cancel|reschedule|payment|review))?$/);

        if (one) {
            const found = bookings.find((b) => b.id === Number(one[1]));

            if (!found) return reply(404);
            if (method === 'GET' || !one[2]) return reply(200, { booking: found });
            if (one[2] === 'cancel') Object.assign(found, { stage: 'cancelled', status: 'cancelled', canCancel: false, canReschedule: false });
            if (one[2] === 'payment') Object.assign(found, { stage: 'payment_review', status: 'payment_review', payment: { ...found.payment, method: 'gcash', submittedAt: new Date().toISOString() } });
            if (one[2] === 'review') Object.assign(found, { canReview: false, review: { rating: 5, body: 'Review', status: 'pending', photo: null } });
            if (one[2] === 'reschedule') found.dropOffOn = JSON.parse(String(init?.body ?? '{}')).date;

            return reply(200, one[2] === 'review' ? { ok: true } : { booking: found });
        }

        if (path === '/bookings' && method === 'POST') {
            const body = JSON.parse(String(init?.body ?? '{}'));
            const made = booking(bookings.length + 1, 'confirmed', 0, { dropOffOn: body.date, readyOn: null, arrivedAt: null, canCancel: true, canReschedule: true, createdAt: new Date().toISOString() });
            bookings.unshift(made);

            return reply(201, { ok: true, booking: made });
        }

        if (path === '/home-requests' && method === 'POST') {
            const made = booking(bookings.length + 1, 'requested', 0, { kind: 'home', dropOffOn: null, readyOn: null, arrivedAt: null, createdAt: new Date().toISOString() });
            bookings.unshift(made);

            return reply(201, { ok: true, booking: made });
        }

        return reply(404);
    }) as typeof fetch;
}
