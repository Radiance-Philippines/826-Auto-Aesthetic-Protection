import { buttonFor, dayLine, filterCards, QUEUE_ORDER, queueFilters, stageChip, STAGES, stepIcon } from '../src/lib/stage';

const next = { to: 'in_progress', label: 'Start work', confirm: false } as const;

describe('stage mapping', () => {
    it('shows the server’s next step, and only to a person who may move units', () => {
        expect(buttonFor({ next, canAdvance: true })).toEqual(next);
        expect(buttonFor({ next, canAdvance: false })).toBeNull();
        expect(buttonFor({ next: null, canAdvance: true })).toBeNull();
    });

    it('gives every step an icon, and an unknown one a fallback', () => {
        for (const to of ['arrived', 'in_progress', 'quality_check', 'ready', 'exited']) expect(stepIcon(to)).toBeDefined();
        expect(stepIcon('something_new')).toBeDefined();
    });

    it('words every stage like the design chips', () => {
        expect(STAGES.in_progress).toEqual({ label: 'In progress', tone: 'teal' });
        expect(STAGES.ready.tone).toBe('green');
        expect(STAGES.quality_check.label).toBe('Quality check');
        expect(stageChip('payment_review').label).toBe('Needs review');
        expect(stageChip('brand_new_status')).toEqual({ label: 'brand new status', tone: 'neutral' });
    });

    it('counts filter chips from the cards: All always, a stage only when it has units', () => {
        const cards = [{ stage: 'arrived' }, { stage: 'arrived' }, { stage: 'in_progress' }, { stage: 'ready' }] as const;
        expect(queueFilters([...cards], QUEUE_ORDER)).toEqual([
            { key: 'all', label: 'All', count: 4 },
            { key: 'arrived', label: 'Arrived', count: 2 },
            { key: 'in_progress', label: 'In progress', count: 1 },
            { key: 'ready', label: 'Ready', count: 1 },
        ]);
        expect(filterCards([...cards], 'arrived')).toHaveLength(2);
        expect(filterCards([...cards], 'all')).toHaveLength(4);
    });

    it('writes "Day n of m" only from what the server sent', () => {
        expect(dayLine({ stage: 'in_progress', dayLabel: 'Day 1 of 3' })).toBe('Day 1 of 3');
        expect(dayLine({ stage: 'in_progress', dayLabel: null })).toBeNull();
        expect(dayLine({ stage: 'ready', dayLabel: 'Day 3 of 3' })).toBe('Done');
    });
});
