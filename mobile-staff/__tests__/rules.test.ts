import { canSeeContact, canSeeMoney, roleChip } from '@/lib/access';
import { peso } from '@/lib/money';
import { usableQuickReplies } from '@/lib/quick';
import { visibleTabs } from '@/lib/tabs';
import { mockUser } from '@/api/mock';

describe('role to tabs', () => {
    it('keeps the server order, adds Me last, caps at five', () => {
        expect(visibleTabs(['queue', 'me'])).toEqual(['queue', 'me']);
        expect(visibleTabs(['today', 'clients', 'inbox'])).toEqual(['today', 'clients', 'inbox', 'me']);
        expect(visibleTabs(['queue', 'today', 'clients', 'inbox', 'overview', 'me'])).toEqual(['queue', 'today', 'clients', 'inbox', 'me']);
    });

    it('drops tabs this app does not know and tolerates capitals', () => {
        expect(visibleTabs(['Queue', 'garage', 'Overview'])).toEqual(['queue', 'overview', 'me']);
        expect(visibleTabs(undefined)).toEqual(['me']);
    });

    it.each([
        ['operations', ['queue', 'me']],
        ['sales_admin', ['today', 'clients', 'inbox', 'me']],
        ['branch_manager', ['queue', 'today', 'clients', 'inbox', 'me']],
        ['super_admin', ['queue', 'today', 'inbox', 'overview', 'me']],
    ] as const)('%s sees what the server sends', (role, tabs) => {
        expect(visibleTabs(mockUser(role).tabs)).toEqual(tabs);
    });
});

describe('what each role may see', () => {
    it('money is never for the detailer', () => {
        expect(canSeeMoney(mockUser('operations'))).toBe(false);
        expect(canSeeMoney(mockUser('sales_admin'))).toBe(true);
        expect(canSeeMoney(mockUser('super_admin'))).toBe(true);
    });

    it('contact is not for the detailer', () => {
        expect(canSeeContact(mockUser('operations'))).toBe(false);
        expect(canSeeContact(mockUser('sales_admin'))).toBe(true);
    });

    it('writes the header chip from role and branch', () => {
        expect(roleChip(mockUser('operations'))).toBe('Head detailer · Cainta');
        expect(roleChip(mockUser('super_admin'))).toBe('Owner');
    });
});

describe('money', () => {
    it('formats pesos with thousands separators', () => {
        expect(peso(142500)).toBe('₱142,500');
        expect(peso(0)).toBe('₱0');
        expect(peso(1234.6)).toBe('₱1,235');
    });
});

describe('quick replies', () => {
    it('shows a chip only when the server sent its text', () => {
        expect(usableQuickReplies(undefined)).toEqual([]);
        expect(usableQuickReplies([])).toEqual([]);
        const sent = [
            { key: 'slot_confirmed', label: 'Slot confirmed', text: 'Your slot is confirmed.' },
            { key: 'price_list', label: 'Send price list', text: '' },
            { key: 'location', label: 'Share location', text: '  ' },
        ];
        expect(usableQuickReplies(sent).map((reply) => reply.key)).toEqual(['slot_confirmed']);
    });
});
