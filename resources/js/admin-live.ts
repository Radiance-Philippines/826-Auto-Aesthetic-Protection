import { LiveClient } from './live/client';

/**
 * The admin's live updates: Radiance Flow tells this page that a queue card moved, a booking changed, a message came
 * in or a notification arrived, and the Livewire page on screen (queue, bookings list, inbox, the bell) looks again.
 * Without a socket nothing changes: those screens keep their own polling and refresh.
 */

type LivewireGlobal = { dispatch: (event: string, params?: Record<string, unknown>) => void };

const ticketUrl = document.querySelector<HTMLMetaElement>('meta[name="flow-live-ticket"]')?.content;
const livewire = () => (window as unknown as { Livewire?: LivewireGlobal }).Livewire;

const AREAS = ['queue', 'bookings', 'inbox', 'notify'] as const;
const pending = new Map<string, number | null>();
let timer: number | undefined;

/** A burst of events (one booking touches three topics) is one refresh per area */
function ask(area: string, branch: number | null): void {
    pending.set(area, pending.has(area) && pending.get(area) !== branch ? null : branch);
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        // One call for the whole burst: a Livewire page that skips its render in any call of a request skips it for all
        const areas: Record<string, number | null> = {};

        for (const [name, where] of pending) {
            if (name === 'notify') {
                // The bell is Filament's own component: it already looks again on this event
                livewire()?.dispatch('databaseNotificationsSent', {});
            } else {
                areas[name] = where;
            }
        }

        if (Object.keys(areas).length > 0) {
            livewire()?.dispatch('flow-live', { areas });
        }

        pending.clear();
    }, 150);
}

if (ticketUrl) {
    new LiveClient({
        ticketUrl,
        onEvent: ({ topic, payload }) => {
            const area = AREAS.find((name) => topic.includes(`.${name}.`));

            if (area) {
                ask(area, typeof payload.branch === 'number' ? payload.branch : null);
            }
        },
        onResync: () => AREAS.forEach((area) => ask(area, null)),
    }).start();
}
