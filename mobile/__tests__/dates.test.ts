import { addMonths, dayMonthYear, longDate, manilaToday, minutesUntil, moment, monthTitle, shortDate, stamp, stampOrDate } from '@/lib/dates';
import { readyOn } from '@/lib/wizard';
import type { MonthAvailability } from '@/api';

describe('dates', () => {
    it('formats like the design', () => {
        expect(shortDate('2026-11-24')).toBe('Tue, 24 Nov');
        expect(dayMonthYear('2026-09-14')).toBe('14 Sep 2026');
        expect(longDate('2026-11-24')).toBe('Tuesday, 24 November');
        expect(monthTitle('2026-11')).toBe('November 2026');
    });

    it('does month maths across the year', () => {
        expect(addMonths('2026-11', 2)).toBe('2027-01');
        expect(addMonths('2026-01', -1)).toBe('2025-12');
    });

    it('takes Manila time as +8 hours', () => {
        expect(manilaToday(new Date('2026-11-23T17:00:00Z'))).toBe('2026-11-24');
        expect(manilaToday(new Date('2026-11-23T15:59:00Z'))).toBe('2026-11-23');
        expect(stamp('2026-11-21T09:12:00+08:00')).toBe('Sat, 21 Nov, 9:12 AM');
        expect(stamp('2026-11-21T00:05:00+08:00')).toBe('Sat, 21 Nov, 12:05 AM');
        expect(stamp(null)).toBeNull();
        expect(stamp('nonsense')).toBeNull();
    });

    it('tells a real time from a bare date stored as midnight', () => {
        expect(moment('2026-11-22T00:00:00+08:00')).toMatchObject({ day: '2026-11-22', clock: false });
        expect(moment('2026-11-21T16:00:00Z')).toMatchObject({ day: '2026-11-22', clock: false });
        expect(moment('2026-11-22T12:00:00+08:00')).toMatchObject({ day: '2026-11-22', clock: true });
        expect(stampOrDate('2026-11-22T00:00:00+08:00')).toBe('Sun, 22 Nov');
        expect(stampOrDate('2026-11-22T12:00:00+08:00')).toBe('Sun, 22 Nov, 12:00 PM');
        expect(stampOrDate(null)).toBeNull();
        expect(moment('nonsense')).toBeNull();
    });

    it('counts minutes left on a hold', () => {
        expect(minutesUntil('2026-11-23T10:30:00Z', new Date('2026-11-23T10:00:00Z').getTime())).toBe(30);
        expect(minutesUntil('2026-11-23T09:00:00Z', new Date('2026-11-23T10:00:00Z').getTime())).toBe(0);
        expect(minutesUntil(null)).toBe(0);
    });
});

describe('readyOn', () => {
    const month: MonthAvailability = { month: '2026-11', first: '2026-11-01', last: '2026-11-30', estimate: null, days: { '2026-11-20': 'open', '2026-11-21': 'open', '2026-11-22': 'closed', '2026-11-23': 'open', '2026-11-24': 'open' } };

    it('counts the drop-off day as day 1 and skips closed days', () => {
        expect(readyOn('2026-11-21', 3, { '2026-11': month }, undefined)).toBe('2026-11-24');
        expect(readyOn('2026-11-21', 1, { '2026-11': month }, undefined)).toBe('2026-11-21');
    });

    it('says nothing for a job with no days', () => {
        expect(readyOn('2026-11-21', 0, {}, undefined)).toBeNull();
    });
});
