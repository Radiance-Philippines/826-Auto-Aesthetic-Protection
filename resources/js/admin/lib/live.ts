import { useEffect, useRef } from 'react';

import { LiveClient } from '../../live/client';

type Area = 'queue' | 'bookings' | 'inbox' | 'notify';

/**
 * Calls `onChange` when Radiance Flow says this area changed (at this branch),
 * and after a reconnect that may have missed something. Without a ticket URL
 * (live updates not set up) nothing happens.
 */
export function useLive(ticketUrl: string | null, area: Area, branch: number | null, onChange: () => void): void {
    const latest = useRef({ branch, onChange });
    latest.current = { branch, onChange };

    useEffect(() => {
        if (!ticketUrl) {
            return;
        }

        let timer: number | undefined;
        // A burst of events is one refresh
        const ask = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => latest.current.onChange(), 150);
        };

        const client = new LiveClient({
            ticketUrl,
            onEvent: ({ topic, payload }) => {
                const where = latest.current.branch;

                if (topic.includes(`.${area}.`) && (where === null || typeof payload.branch !== 'number' || payload.branch === where)) {
                    ask();
                }
            },
            onResync: ask,
        });
        client.start();

        return () => {
            window.clearTimeout(timer);
            client.stop();
        };
    }, [ticketUrl, area]);
}
