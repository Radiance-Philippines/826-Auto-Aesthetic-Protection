import { isEnded, STAGES, stepIndex, timeline } from '@/lib/stage';
import type { Stage } from '@/api';

const base = { arrivedAt: '2026-11-21T09:12:00+08:00', exitedAt: null, readyOn: '2026-11-24', createdAt: '2026-11-19T15:20:00+08:00' };

describe('stage mapping', () => {
    it('puts in-shop stages on the four dots', () => {
        expect(['arrived', 'in_progress', 'quality_check', 'ready'].map((s) => stepIndex(s as Stage))).toEqual([0, 1, 2, 3]);
        expect(stepIndex('confirmed')).toBe(-1);
    });

    it('has words for every stage and knows the ended ones', () => {
        expect(Object.keys(STAGES)).toHaveLength(13);
        expect(isEnded('exited')).toBe(true);
        expect(isEnded('ready')).toBe(false);
    });
});

describe('timeline', () => {
    it('marks done, current and next for an in-progress job (C3)', () => {
        const rows = timeline({ ...base, stage: 'in_progress' });
        expect(rows.map((r) => r.state)).toEqual(['done', 'done', 'current', 'next', 'next', 'next']);
        expect(rows[0].detail).toBe('Thu, 19 Nov, 3:20 PM');
        expect(rows[1].detail).toBe('Sat, 21 Nov, 9:12 AM');
        expect(rows[2].detail).toMatch(/^Now/);
        expect(rows[4].detail).toBe('Tue, 24 Nov (estimate)');
    });

    it('finishes everything once the unit is picked up', () => {
        expect(timeline({ ...base, stage: 'exited', exitedAt: '2026-11-25T16:00:00+08:00' }).every((r) => r.state === 'done')).toBe(true);
    });

    it('starts at Booked while the fee is pending, and has no line for a cancelled booking', () => {
        expect(timeline({ ...base, stage: 'awaiting_payment' })[0].state).toBe('current');
        expect(timeline({ ...base, stage: 'cancelled' })).toEqual([]);
    });
});
