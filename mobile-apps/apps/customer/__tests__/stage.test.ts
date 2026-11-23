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

describe('timeline order and times', () => {
    const rows = (extra: object, stage: Stage = 'in_progress') => timeline({ ...base, stage, ...extra });

    it('keeps Booked, Arrived, In progress, Quality check, Ready, Picked up in that order', () => {
        expect(rows({}).map((r) => r.label)).toEqual(['Booked', 'Arrived', 'In progress', 'Quality check', 'Ready', 'Picked up']);
    });

    it('shows only the date when the stored value has no time (midnight), never "12:00 AM"', () => {
        const line = rows({ arrivedAt: '2026-11-19T00:00:00+08:00' });
        expect(line[1].detail).toBe('Thu, 19 Nov');
        expect(JSON.stringify(line)).not.toContain('12:00 AM');
    });

    it('shows nothing for a step that would come before the step above it (the 22 Nov arrival, 23 Nov booking case)', () => {
        const line = rows({ createdAt: '2026-11-23T15:08:00+08:00', arrivedAt: '2026-11-22T00:00:00+08:00' });
        expect(line[0].detail).toBe('Mon, 23 Nov, 3:08 PM');
        expect(line[1].detail).toBeNull();
        expect(line[1].state).toBe('done');
        // a real time that is earlier is hidden too
        expect(rows({ createdAt: '2026-11-23T15:08:00+08:00', arrivedAt: '2026-11-23T09:12:00+08:00' })[1].detail).toBeNull();
    });

    it('allows a bare date on the same day as the booking, and a later real time', () => {
        expect(rows({ createdAt: '2026-11-19T15:20:00+08:00', arrivedAt: '2026-11-19T00:00:00+08:00' })[1].detail).toBe('Thu, 19 Nov');
        expect(rows({ createdAt: '2026-11-19T15:20:00+08:00', arrivedAt: '2026-11-19T16:00:00+08:00' })[1].detail).toBe('Thu, 19 Nov, 4:00 PM');
    });

    it('checks Picked up against Arrived, and the Ready estimate against the last time shown', () => {
        const done = rows({ arrivedAt: '2026-11-19T09:00:00+08:00', exitedAt: '2026-11-20T10:00:00+08:00', readyOn: '2026-11-20' }, 'exited');
        expect(done[5].detail).toBe('Fri, 20 Nov, 10:00 AM');
                expect(rows({ exitedAt: '2026-11-20T10:00:00+08:00', arrivedAt: '2026-11-21T09:12:00+08:00' }, 'exited')[5].detail).toBeNull();
        expect(rows({ readyOn: '2026-11-18' })[4].detail).toBe('Next');
    });

    it('never goes backwards in any combination of stored values', () => {
        const stamps = [null, '2026-11-18T00:00:00+08:00', '2026-11-19T00:00:00+08:00', '2026-11-19T10:00:00+08:00', '2026-11-21T09:12:00+08:00', '2026-11-25T16:00:00+08:00'];
        const parse = (text: string | null) => (text ? new Date(`${text.replace(/, (\d+:\d+ [AP]M)$/, '')} 2026`).getTime() : null);

        for (const createdAt of stamps) for (const arrivedAt of stamps) for (const exitedAt of stamps) {
            const shown = timeline({ ...base, stage: 'exited', createdAt, arrivedAt, exitedAt, readyOn: null })
                .filter((r) => ['booked', 'arrived', 'exited'].includes(r.key) && r.detail)
                .map((r) => parse(r.detail));
            expect(shown).toEqual([...shown].sort((a, b) => (a as number) - (b as number)));
        }
    });
});
