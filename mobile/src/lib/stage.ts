import type { CustomerBooking, Stage } from '@/api/types';
import type { Tone } from '@/theme';
import { stamp, shortDate } from './dates';

/** The site's words for each stage (resources/js/lib/copy.ts `stages`) */
export const STAGES: Record<Stage, { label: string; body: string; tone: Tone }> = {
    requested: { label: 'Request sent', body: 'The branch will call or text you to agree on a time.', tone: 'gold' },
    awaiting_payment: { label: 'Pay the reservation fee', body: 'Pay by GCash or Maya and send a screenshot to keep your date.', tone: 'gold' },
    payment_review: { label: 'Checking your payment', body: 'Your screenshot is in. The branch confirms your date once they’ve checked it.', tone: 'gold' },
    confirmed: { label: 'Booked', body: 'Your date is yours. Bring the unit on your drop-off day.', tone: 'neutral' },
    arrived: { label: 'Arrived', body: 'Your unit is with the team now.', tone: 'blue' },
    in_progress: { label: 'In progress', body: 'The team is working on your unit.', tone: 'teal' },
    quality_check: { label: 'Quality check', body: 'The work is done and is being checked.', tone: 'gold' },
    ready: { label: 'Ready', body: 'Your unit is ready. Come pick it up.', tone: 'green' },
    exited: { label: 'Exited', body: 'Your unit has left the shop. Thanks for trusting 826 with it.', tone: 'neutral' },
    expired: { label: 'Hold ran out', body: 'The reservation fee didn’t come in on time, so the date opened up.', tone: 'red' },
    cancelled: { label: 'Cancelled', body: 'This booking was cancelled.', tone: 'neutral' },
    declined: { label: 'Declined', body: 'The branch couldn’t take this booking. Call or message them, or book another date.', tone: 'red' },
    no_show: { label: 'Missed drop-off', body: 'The unit didn’t arrive on the drop-off day. Book again whenever you’re ready.', tone: 'red' },
};

/** Stages that are over: finished, cancelled or lapsed */
export const ENDED: Stage[] = ['exited', 'expired', 'cancelled', 'declined', 'no_show'];
/** The unit is in the shop */
export const IN_SHOP: Stage[] = ['arrived', 'in_progress', 'quality_check', 'ready'];

export const isEnded = (stage: Stage) => ENDED.includes(stage);

/** The home screen's four dots: Arrived, Working, Check, Ready */
export const STEP_LABELS = ['Arrived', 'Working', 'Check', 'Ready'] as const;

/** Index of the dot a stage sits on (0..3), or -1 when the unit isn't in the shop */
export function stepIndex(stage: Stage): number {
    return IN_SHOP.indexOf(stage);
}

export type TimelineState = 'done' | 'current' | 'next';
export type TimelineRow = { key: string; label: string; detail: string | null; state: TimelineState };

const FLOW: Stage[] = ['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready', 'exited'];
const EARLY: Stage[] = ['requested', 'awaiting_payment', 'payment_review'];

/**
 * The booking's line (C3): Booked, Arrived, In progress, Quality check, Ready,
 * Picked up. Only times the server sent are shown; nothing is guessed.
 * Cancelled, expired and the like have no line.
 */
export function timeline(booking: Pick<CustomerBooking, 'stage' | 'arrivedAt' | 'exitedAt' | 'readyOn' | 'createdAt'>): TimelineRow[] {
    const now = EARLY.includes(booking.stage) ? 0 : FLOW.indexOf(booking.stage);

    if (now === -1) {
        return [];
    }

    const rows: { key: string; label: string; detail: string | null; current: string | null; next: string }[] = [
        { key: 'booked', label: 'Booked', detail: stamp(booking.createdAt), current: null, next: '' },
        { key: 'arrived', label: 'Arrived', detail: stamp(booking.arrivedAt), current: null, next: 'After you drop it off' },
        { key: 'in_progress', label: 'In progress', detail: null, current: 'Now · the team is working on it', next: 'Next' },
        { key: 'quality_check', label: 'Quality check', detail: null, current: 'Now · being checked', next: 'Next' },
        { key: 'ready', label: 'Ready', detail: booking.readyOn ? `${shortDate(booking.readyOn)} (estimate)` : null, current: 'Now · ready for pickup', next: 'Next' },
        { key: 'exited', label: 'Picked up', detail: stamp(booking.exitedAt), current: null, next: 'After you collect it' },
    ];

    return rows.map((row, index) => {
        const state: TimelineState = index < now || (index === now && booking.stage === 'exited') ? 'done' : index === now ? 'current' : 'next';
        const detail = state === 'next' ? (row.detail ?? row.next) : state === 'current' ? (row.current ?? row.detail) : row.detail;

        return { key: row.key, label: row.label, detail: detail || null, state };
    });
}
