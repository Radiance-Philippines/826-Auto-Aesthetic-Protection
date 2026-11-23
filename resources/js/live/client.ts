import { decodeServerFrame, pingFrame, subscribeFrame } from './proto';

/**
 * One socket to Radiance Flow for the whole page (the admin and the site share this file). It asks this app for a
 * ticket (GET /live/ticket), opens the socket, subscribes to every topic the ticket lists, and tells the page when
 * something changed. Browsers never send through the socket. It reconnects with backoff, asks for a new ticket each
 * time, resumes by offset, rests while the tab is hidden, and when the socket is not available at all (no ticket,
 * blocked, offline) it simply stays quiet: the page keeps its own refresh.
 */

export type LiveEvent = { topic: string; type: string; payload: Record<string, unknown>; offset: number };

export type LiveOptions = {
    /** Where the ticket comes from (same origin, session cookie) */
    ticketUrl: string;
    onEvent: (event: LiveEvent) => void;
    /** After every (re)connect that may have missed something: look again at everything */
    onResync?: () => void;
    onStatus?: (status: 'connecting' | 'live' | 'off') => void;
};

type Ticket = { ticket: string; topics: string[]; expiresIn: number; url: string };

const PING_MS = 30_000;
const BACKOFF_MS = [1_000, 2_000, 5_000, 10_000, 20_000, 30_000];
/** Live updates are not set up (503): check again rarely */
const OFF_RETRY_MS = 5 * 60_000;

export class LiveClient {
    private socket: WebSocket | null = null;
    private timer: number | undefined;
    private pinger: number | undefined;
    private attempt = 0;
    private stopped = false;
    private offsets = new Map<string, number>();
    private connectedBefore = false;

    constructor(private options: LiveOptions) {
        document.addEventListener('visibilitychange', this.onVisibility);
        window.addEventListener('online', this.onVisibility);
    }

    start(): void {
        this.stopped = false;
        this.connect();
    }

    stop(): void {
        this.stopped = true;
        window.clearTimeout(this.timer);
        this.closeSocket();
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('online', this.onVisibility);
        this.options.onStatus?.('off');
    }

    private onVisibility = (): void => {
        if (this.stopped || document.visibilityState !== 'visible') {
            return;
        }

        if (!this.socket || this.socket.readyState > WebSocket.OPEN) {
            window.clearTimeout(this.timer);
            this.connect();
        }
    };

    private closeSocket(): void {
        window.clearInterval(this.pinger);

        if (this.socket) {
            this.socket.onclose = null;
            this.socket.onerror = null;
            this.socket.onmessage = null;
            this.socket.close();
            this.socket = null;
        }
    }

    private schedule(delay?: number): void {
        if (this.stopped) {
            return;
        }

        const wait = delay ?? BACKOFF_MS[Math.min(this.attempt, BACKOFF_MS.length - 1)] * (0.75 + Math.random() * 0.5);
        this.attempt++;
        this.options.onStatus?.('off');
        window.clearTimeout(this.timer);
        // A hidden tab does not keep trying: coming back (visibilitychange) reconnects at once
        this.timer = window.setTimeout(() => (document.visibilityState === 'visible' ? this.connect() : undefined), wait);
    }

    private async fetchTicket(): Promise<Ticket | 'off' | 'failed'> {
        try {
            const response = await fetch(this.options.ticketUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });

            if (response.status === 503 || response.status === 401) {
                return 'off';
            }

            return response.ok ? ((await response.json()) as Ticket) : 'failed';
        } catch {
            return 'failed';
        }
    }

    private async connect(): Promise<void> {
        if (this.stopped || typeof WebSocket === 'undefined') {
            return;
        }

        this.closeSocket();
        this.options.onStatus?.('connecting');

        const ticket = await this.fetchTicket();

        if (this.stopped) {
            return;
        }

        if (ticket === 'off') {
            this.schedule(OFF_RETRY_MS);

            return;
        }

        if (ticket === 'failed' || ticket.topics.length === 0) {
            this.schedule();

            return;
        }

        let socket: WebSocket;

        try {
            socket = new WebSocket(ticket.url);
        } catch {
            this.schedule();

            return;
        }

        socket.binaryType = 'arraybuffer';
        let subscribed = false;
        this.socket = socket;

        socket.onmessage = (message) => {
            if (!(message.data instanceof ArrayBuffer)) {
                return;
            }

            const frame = decodeServerFrame(new Uint8Array(message.data));

            if (frame.kind === 'hello' && frame.subscribed === '') {
                for (const topic of ticket.topics) {
                    socket.send(subscribeFrame(topic, ticket.ticket, topic, this.offsets.get(topic) ?? 0));
                }

                return;
            }

            if (frame.kind === 'hello') {
                if (!subscribed) {
                    subscribed = true;
                    this.onSubscribed();
                }

                return;
            }

            if (frame.kind === 'event') {
                this.offsets.set(frame.topic, Math.max(frame.offset, this.offsets.get(frame.topic) ?? 0));

                let payload: Record<string, unknown> = {};

                try {
                    payload = JSON.parse(frame.payload) as Record<string, unknown>;
                } catch {
                    payload = {};
                }

                this.options.onEvent({ topic: frame.topic, type: frame.type, payload, offset: frame.offset });

                return;
            }

            if (frame.kind === 'error' && frame.code === 401) {
                // The ticket ran out: a new one, then subscribe again
                this.closeSocket();
                this.connect();
            }
        };

        socket.onclose = () => {
            this.socket = null;
            window.clearInterval(this.pinger);
            this.schedule();
        };

        socket.onerror = () => socket.close();

        this.pinger = window.setInterval(() => {
            if (socket.readyState === WebSocket.OPEN) {
                socket.send(pingFrame(Date.now()));
            }
        }, PING_MS);
    }

    private onSubscribed(): void {
        this.attempt = 0;
        this.options.onStatus?.('live');

        // Events missed while it was down are replayed by offset, but a first connect after a long gap may have lost some
        if (this.connectedBefore) {
            this.options.onResync?.();
        }

        this.connectedBefore = true;
    }
}
