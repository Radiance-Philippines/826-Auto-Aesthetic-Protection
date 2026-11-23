/*
 * The screens against the backend's own example responses (copied from the
 * staff API's feature-test dump), so a shape the app misreads shows up here.
 */
import { api } from '../src/api';
import { QueueScreen } from '../src/features/Queue';
import { UnitScreen } from '../src/features/Unit';
import { TodayScreen } from '../src/features/Today';
import { ClientScreen, ClientsScreen } from '../src/features/Clients';
import { InboxScreen, ThreadScreen } from '../src/features/Inbox';
import { OverviewScreen } from '../src/features/Overview';
import { visibleTabs } from '../src/lib/tabs';
import { dump, renderAs } from '../test-utils/helpers';
import examples from './fixtures/staff-examples.json';

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));

const ex = examples as unknown as Record<string, { status: number; body: any }>;
const ok = (key: string) => async () => ({ kind: 'ok', data: ex[key].body }) as never;
const stub = (name: keyof typeof api, key: string) => jest.spyOn(api, name).mockImplementation(ok(key));

afterEach(() => jest.restoreAllMocks());

describe('real response shapes', () => {
    it('queue', async () => {
        stub('queue', 'queue');
        const text = dump(await renderAs(<QueueScreen />, 'branch_manager'));
        expect(text).toContain('Toyota Vios');
        expect(text).toContain('Mark arrived');
        expect(text).toContain(ex.queue.body.heading);
    });

    it('unit as the sales side: money, contact and buttons from can.*', async () => {
        stub('unit', 'booking-detail-sales');
        const text = dump(await renderAs(<UnitScreen id={1} />, 'branch_manager'));
        expect(text).toContain('Maria Santos');
        expect(text).toContain('Total ₱');
        // buttons follow can.*, not the presence of `next`
        const can = ex['booking-detail-sales'].body.can;
        expect(text.includes('Mark arrived')).toBe(can.advance);
        expect(text.includes('Add photo')).toBe(can.addPhoto);
        expect(text.includes('Add note')).toBe(can.addNote);
    });

    it('unit for the detailer: no money when money is null', async () => {
        stub('unit', 'booking-detail-detailer');
        const text = dump(await renderAs(<UnitScreen id={1} />, 'operations'));
        expect(ex['booking-detail-detailer'].body.money).toBeNull();
        expect(text).not.toContain('₱');
        expect(text).not.toContain('Total');
        expect(text).toContain('Add note');
    });

    it('today, clients, client, inbox, thread, overview', async () => {
        stub('today', 'today');
        stub('customers', 'customers');
        stub('customer', 'customer');
        stub('inbox', 'inbox');
        stub('thread', 'inbox-thread');
        stub('quickReplies', 'inbox-quick-replies');
        stub('overview', 'overview');
        const today = dump(await renderAs(<TodayScreen />, 'branch_manager'));
        expect(today).toContain('Payments to check');
        expect(today).toContain('12 Rizal St');
        expect(dump(await renderAs(<ClientsScreen />, 'branch_manager'))).toContain(ex.customers.body.data[0].name);
        expect(dump(await renderAs(<ClientScreen id={1} />, 'branch_manager'))).toContain('Prefers Saturdays');
        expect(dump(await renderAs(<InboxScreen />, 'sales_admin'))).toContain(ex.inbox.body.conversations[0].name);
        const thread = dump(await renderAs(<ThreadScreen id={1} />, 'sales_admin'));
        expect(thread).toContain('Maria says hi');
        expect(thread).toContain(ex['inbox-quick-replies'].body.replies[0].label);
        expect(dump(await renderAs(<OverviewScreen />, 'branch_manager'))).toContain('Top sales reps');
    });

    it('overview for the detailer shows counts only', async () => {
        stub('overview', 'overview-detailer');
        const text = dump(await renderAs(<OverviewScreen />, 'operations'));
        expect(text).toContain('Units done');
        expect(text).not.toContain('₱');
        expect(text).not.toContain('Top sales reps');
    });

    it('me tabs from the server', () => {
        expect(visibleTabs(ex.me.body.tabs)).toEqual([...ex.me.body.tabs, 'me'].filter((t: string, i: number, all: string[]) => all.indexOf(t) === i));
    });
});
