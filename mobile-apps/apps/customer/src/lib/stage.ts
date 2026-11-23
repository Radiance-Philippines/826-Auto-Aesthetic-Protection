import type { CustomerBooking, Stage } from '@/api/types';
import { moment, shortDate, stampOrDate, type Moment } from '@826/core';
import type { Tone } from '@826/core';

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

/** A step is not earlier than the one before it: a time against a time, a bare date against the day */
const notBefore = (at: Moment, before: Moment | null) => !before || (at.clock && before.clock ? at.ms >= before.ms : at.day >= before.day);

/**
 * The booking's line (C3): Booked, Arrived, In progress, Quality check, Ready,
 * Picked up. Only times the server sent are shown; nothing is guessed. A
 * time shows its clock only when the stored value has one (a bare date shows
 * just the day), and a step that would read earlier than the one before it
 * shows no time at all. Cancelled, expired and the like have no line.
 */
export function timeline(booking: Pick<CustomerBooking, 'stage' | 'arrivedAt' | 'exitedAt' | 'readyOn' | 'createdAt'>): TimelineRow[] {
    const now = EARLY.includes(booking.stage) ? 0 : FLOW.indexOf(booking.stage);

    if (now === -1) {
        return [];
    }

    // The latest time shown so far: each stamped step must not come before it
    let last: Moment | null = moment(booking.createdAt);
    const inOrder = (value: string | null | undefined): string | null => {
        const at = moment(value);

        if (!at || !notBefore(at, last)) {
            return null;
        }

        last = at;

        return stampOrDate(value);
    };
    const booked = stampOrDate(booking.createdAt);
    const arrived = inOrder(booking.arrivedAt);
    const ready = booking.readyOn && notBefore({ ms: 0, day: booking.readyOn, clock: false, hours: 0, minutes: 0 }, last) ? `${shortDate(booking.readyOn)} (estimate)` : null;
    const exited = inOrder(booking.exitedAt);

    const rows: { key: string; label: string; detail: string | null; current: string | null; next: string }[] = [
        { key: 'booked', label: 'Booked', detail: booked, current: null, next: '' },
        { key: 'arrived', label: 'Arrived', detail: arrived, current: null, next: 'After you drop it off' },
        { key: 'in_progress', label: 'In progress', detail: null, current: 'Now · the team is working on it', next: 'Next' },
        { key: 'quality_check', label: 'Quality check', detail: null, current: 'Now · being checked', next: 'Next' },
        { key: 'ready', label: 'Ready', detail: ready, current: 'Now · ready for pickup', next: 'Next' },
        { key: 'exited', label: 'Picked up', detail: exited, current: null, next: 'After you collect it' },
    ];

    return rows.map((row, index) => {
        const state: TimelineState = index < now || (index === now && booking.stage === 'exited') ? 'done' : index === now ? 'current' : 'next';
        const detail = state === 'next' ? (row.detail ?? row.next) : state === 'current' ? (row.current ?? row.detail) : row.detail;

        return { key: row.key, label: row.label, detail: detail || null, state };
    });
}
