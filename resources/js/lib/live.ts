import { useEffect, useRef } from 'react';

import type { LiveClient, LiveEvent } from '../live/client';
import { guest } from './auth';
import { site } from './site';

/**
 * Live updates for a signed-in customer (Radiance Flow, through this site's own ticket). One socket for the whole
 * page, opened when the first screen asks and closed when the last one leaves; the socket code is loaded only then,
 * so it costs a first visit nothing. Without a socket (not set up, blocked, offline) nothing happens and every
 * screen keeps the refresh it already had.
 */

type Listener = { event: (event: LiveEvent) => void; resync: () => void };

const listeners = new Set<Listener>();
let client: LiveClient | null = null;
let starting = false;

async function open(): Promise<void> {
    if (client || starting) {
        return;
    }

    starting = true;
    const { LiveClient } = await import('../live/client');
    starting = false;

    if (listeners.size === 0) {
        return;
    }

    client = new LiveClient({
        ticketUrl: `${site.base}/live/ticket`,
        onEvent: (event) => listeners.forEach((listener) => listener.event(event)),
        onResync: () => listeners.forEach((listener) => listener.resync()),
    });
    client.start();
}

/**
 * Calls `onEvent` for each event (their own bookings and points, nothing else comes down the line) and `onResync`
 * after a reconnect, when something may have been missed. Both can change between renders.
 */
export function useLive(onEvent: (event: LiveEvent) => void, onResync?: () => void): void {
    const latest = useRef({ onEvent, onResync });
    latest.current = { onEvent, onResync };

    useEffect(() => {
        if (!guest) {
            return;
        }

        const listener: Listener = { event: (event) => latest.current.onEvent(event), resync: () => latest.current.onResync?.() };
        listeners.add(listener);
        void open();

        return () => {
            listeners.delete(listener);

            if (listeners.size === 0 && client) {
                client.stop();
                client = null;
            }
        };
    }, []);
}
