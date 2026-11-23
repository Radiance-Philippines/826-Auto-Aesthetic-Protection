import { QueueScreen } from '@/features/Queue';
import { UnitScreen } from '@/features/Unit';
import { TodayScreen } from '@/features/Today';
import { ClientScreen, ClientsScreen } from '@/features/Clients';
import { InboxScreen, ThreadScreen } from '@/features/Inbox';
import { OverviewScreen } from '@/features/Overview';
import { MeScreen } from '@/features/Me';
import { dump, press, renderAs, type as typeInto } from '../test-utils/helpers';

const mockPush = jest.fn();
jest.mock('expo-router', () => ({ useRouter: () => ({ push: mockPush, back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));
jest.mock('@/lib/photo', () => ({ pickPhoto: jest.fn(async () => ({ uri: 'file:///p.jpg', name: 'p.jpg', type: 'image/jpeg' })), takePhoto: jest.fn(async () => ({ uri: 'file:///p.jpg', name: 'p.jpg', type: 'image/jpeg' })) }));

beforeEach(() => mockPush.mockClear());

describe('S2 queue', () => {
    it('shows the role chip, the filter counts and each unit with its next action', async () => {
        const screen = await renderAs(<QueueScreen />, 'operations');
        const text = dump(screen);
        for (const word of ['Head detailer · Cainta', 'Today’s queue', 'All, 3', 'Arrived, 1', 'In progress, 1', 'Ready, 1', '2023 BMW 7 Series', 'Sedan · NDA 4821', 'Day 1 of 3', 'Done', 'Send to quality check', 'Start work', 'Mark as exited']) {
            expect(text).toContain(word);
        }
    });

    it('filters by stage', async () => {
        const screen = await renderAs(<QueueScreen />, 'operations');
        await press(screen, 'Ready, 1');
        const text = dump(screen);
        expect(text).toContain('Porsche 911 Carrera');
        expect(text).not.toContain('BMW 7 Series');
    });

    it('moves a unit one step', async () => {
        const screen = await renderAs(<QueueScreen />, 'operations');
        await press(screen, 'Start work');
        expect(dump(screen)).not.toContain('Start work');
        expect(dump(screen)).toContain('Send to quality check');
    });

    it('asks before Mark as exited', async () => {
        const screen = await renderAs(<QueueScreen />, 'operations');
        await press(screen, 'Mark as exited');
        expect(dump(screen)).toContain('Mark as exited?');
        await press(screen, 'Cancel');
    });

    it('opens a unit', async () => {
        const screen = await renderAs(<QueueScreen />, 'operations');
        await press(screen, '2023 BMW 7 Series, In progress. Open');
        expect(mockPush).toHaveBeenCalledWith('/unit/1');
    });

    it('gives sales no buttons that move units', async () => {
        const screen = await renderAs(<QueueScreen />, 'sales_admin');
        expect(dump(screen)).not.toContain('Start work');
    });
});

describe('S3 unit', () => {
    it('shows the stage list, ready date, photo and note buttons for the detailer, with no money or contact', async () => {
        const screen = await renderAs(<UnitScreen id={1} />, 'operations');
        const text = dump(screen);
        for (const word of ['Booked', 'Arrived', 'In progress', 'Quality check', 'Ready', 'Exited', 'Send to quality check', 'Add photo', 'Add note', 'Change', 'ready by', 'History', 'Status: Booked → Arrived']) {
            expect(text).toContain(word);
        }
        expect(text).not.toContain('₱');
        expect(text).not.toContain('Total');
        expect(text).not.toContain('0917');
        expect(text).not.toContain('Miguel Santos');
    });

    it('shows the customer and the total to someone in sales, with no queue buttons', async () => {
        const screen = await renderAs(<UnitScreen id={1} />, 'sales_admin');
        const text = dump(screen);
        expect(text).toContain('Miguel Santos');
        expect(text).toContain('Total ₱48,000');
        expect(text).not.toContain('Add photo');
        expect(text).not.toContain('Send to quality check');
    });

    it('never shows money to the detailer even if a payload carried it', async () => {
        const { api } = require('@/api');
        const original = api.unit;
        api.unit = async (id: number) => {
            const result = await original(id);

            return result.kind === 'ok' ? { kind: 'ok', data: { ...result.data, money: { priced: true, total: 99999, amountPaid: 99999 } } } : result;
        };
        const screen = await renderAs(<UnitScreen id={1} />, 'operations');
        api.unit = original;
        expect(dump(screen)).not.toContain('99,999');
    });

    it('adds a note to the history', async () => {
        const screen = await renderAs(<UnitScreen id={1} />, 'operations');
        await press(screen, 'Add note');
        await typeInto(screen, 'Note', 'Scratch on the left door');
        await press(screen, 'Save note');
        expect(dump(screen)).toContain('Scratch on the left door');
    });

    it('adds a photo', async () => {
        const screen = await renderAs(<UnitScreen id={1} />, 'operations');
        await press(screen, 'Add photo');
        await press(screen, 'Take a photo');
        expect(dump(screen)).toContain('Photo added');
    });

    it('changes the ready date through the calendar sheet', async () => {
        const screen = await renderAs(<UnitScreen id={1} />, 'operations');
        await press(screen, 'Change the ready date');
        expect(dump(screen)).toContain('Save ready date');
        expect(dump(screen)).toContain('Next month');
    });
});

describe('S6 today (sales)', () => {
    it('lists drop-offs, sizes, payments and home requests', async () => {
        const screen = await renderAs(<TodayScreen />, 'sales_admin');
        const text = dump(screen);
        for (const word of ['Drop-offs today', 'Needs a size', 'Payments to check', 'Home requests', 'Set size', 'Approve', 'Reject', '₱500', 'Payment screenshot', 'Sample street']) {
            expect(text).toContain(word);
        }
    });

    it('sets a size from the sheet', async () => {
        const screen = await renderAs(<TodayScreen />, 'sales_admin');
        await press(screen, 'Set size for 2021 Range Rover');
        expect(dump(screen)).toContain('Medium');
        await press(screen, 'Size Medium, Sedan');
    });

    it('asks for a reason to reject a payment', async () => {
        const screen = await renderAs(<TodayScreen />, 'sales_admin');
        await press(screen, 'Reject payment for 2023 Mercedes-AMG G 63');
        expect(dump(screen)).toContain('Reject payment');
        await typeInto(screen, 'Reason', 'Wrong amount');
        await press(screen, 'Reject payment');
    });
});

describe('S4 and S5 clients', () => {
    it('lists clients and opens one', async () => {
        const screen = await renderAs(<ClientsScreen />, 'sales_admin');
        expect(dump(screen)).toContain('Miguel Santos');
        await press(screen, 'Miguel Santos, 1 unit. Open');
        expect(mockPush).toHaveBeenCalledWith('/client/5');
    });

    it('shows a profile with units, bookings, notes and a Messenger link', async () => {
        const screen = await renderAs(<ClientScreen id={5} />, 'sales_admin');
        const text = dump(screen);
        for (const word of ['Miguel Santos', '0917 123 4567', 'MIGUEL-826', '2023 BMW 7 Series', 'Graphene coating', 'Save notes', 'Messenger']) {
            expect(text).toContain(word);
        }
        await press(screen, 'Messenger');
        expect(mockPush).toHaveBeenCalledWith('/thread/7');
    });
});

describe('inbox', () => {
    it('lists chats for a branch', async () => {
        const screen = await renderAs(<InboxScreen />, 'sales_admin');
        expect(dump(screen)).toContain('Miguel Santos');
        expect(dump(screen)).not.toContain('Quezon City');
    });

    it('gives the owner a tab for each branch and All', async () => {
        const screen = await renderAs(<InboxScreen />, 'super_admin');
        const text = dump(screen);
        expect(text).toContain('Quezon City');
        expect(text).toContain('All, 1');
    });

    it('shows the thread, only the quick replies the server sent, and sends a reply', async () => {
        const screen = await renderAs(<ThreadScreen id={7} />, 'sales_admin');
        const text = dump(screen);
        expect(text).toContain('Hi! Can I bring the Land Cruiser in for PPF this week?');
        expect(text).toContain('Slot confirmed');
        expect(text).not.toContain('Send price list');
        expect(text).not.toContain('Share location');
        await typeInto(screen, 'Reply', 'Yes, see you Wednesday');
        await press(screen, 'Send');
        expect(dump(screen)).toContain('Yes, see you Wednesday');
        await press(screen, 'Mark done');
        expect(dump(screen)).toContain('Reopen');
    });
});

describe('S7 overview', () => {
    it('shows the cards the server returned', async () => {
        const screen = await renderAs(<OverviewScreen />, 'super_admin');
        const text = dump(screen);
        for (const word of ['Owner', 'Sales', '₱412,800', 'Units done', 'Branches', 'Quezon City', 'Top sales reps', 'Joanna Cruz', 'Backjobs open', 'Reviews', '4.8', 'Needs attention']) {
            expect(text).toContain(word);
        }
    });

    it('leaves out a card the server did not return', async () => {
        const { api } = require('@/api');
        const original = api.overview;
        api.overview = async () => ({ kind: 'ok', data: { period: { key: 'today', label: 'Today', from: '', to: '' }, branch: null, branches: [], sales: null, collected: null, averageTicket: null, unitsDone: 4, turnaround: null, branchesTable: null, topReps: null, backjobsOpen: null, reviewRating: null, attention: { size: null, screenshots: null, home: null, reviews: null, chats: null, late: null } } });
        const screen = await renderAs(<OverviewScreen />, 'branch_manager');
        api.overview = original;
        const text = dump(screen);
        expect(text).toContain('Units done');
        for (const word of ['Branches', 'Top sales reps', 'Backjobs open', 'Reviews', 'Needs attention', '₱']) expect(text).not.toContain(word);
    });
});

describe('Me', () => {
    it('shows the profile, role, branches, version and sign out', async () => {
        const screen = await renderAs(<MeScreen />, 'branch_manager');
        const text = dump(screen);
        for (const word of ['Mark Dela Cruz', 'Branch manager', 'Cainta', '826 Team 0.1.0', 'Sign out']) expect(text).toContain(word);
    });
});

describe('Me overflow', () => {
    it('links Clients from Me when it did not fit the tab bar, and only then', async () => {
        const owner = await renderAs(<MeScreen />, 'super_admin');
        await press(owner, 'Clients');
        expect(mockPush).toHaveBeenCalledWith('/clients');
        const manager = await renderAs(<MeScreen />, 'branch_manager');
        expect(dump(manager)).not.toContain('"Clients"');
    });
});

describe('failures', () => {
    it('shows an offline message with a retry', async () => {
        const { api } = require('@/api');
        const original = api.queue;
        api.queue = async () => ({ kind: 'offline' });
        const screen = await renderAs(<QueueScreen />, 'operations');
        api.queue = original;
        expect(dump(screen)).toContain('No connection');
        expect(dump(screen)).toContain('Try again');
    });
});
