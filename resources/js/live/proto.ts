/**
 * The few protobuf messages Radiance Flow's WebSocket speaks (radiance/flow/v1/live.proto), written by hand so the
 * browser needs no library: ClientFrame{subscribe, ping} out, ServerFrame{hello, event, pong, error} in.
 */

const text = new TextEncoder();
const utf8 = new TextDecoder();

function varint(value: number): number[] {
    const bytes: number[] = [];
    let rest = Math.floor(value);

    while (rest >= 0x80) {
        bytes.push((rest % 0x80) | 0x80);
        rest = Math.floor(rest / 0x80);
    }

    bytes.push(rest);

    return bytes;
}

const tag = (field: number, wire: number) => varint(field * 8 + wire);

function stringField(field: number, value: string): number[] {
    if (value === '') {
        return [];
    }

    const bytes = text.encode(value);

    return [...tag(field, 2), ...varint(bytes.length), ...bytes];
}

function numberField(field: number, value: number): number[] {
    return value === 0 ? [] : [...tag(field, 0), ...varint(value)];
}

function messageField(field: number, body: number[]): number[] {
    return [...tag(field, 2), ...varint(body.length), ...body];
}

/** ClientFrame { subscribe = 1 } */
export function subscribeFrame(id: string, ticket: string, topic: string, after: number): Uint8Array<ArrayBuffer> {
    const subscribe = [...stringField(1, id), ...stringField(2, ticket), ...stringField(3, topic), ...numberField(5, after)];

    return Uint8Array.from(messageField(1, subscribe));
}

/** ClientFrame { ping = 4 } */
export function pingFrame(at: number): Uint8Array<ArrayBuffer> {
    return Uint8Array.from(messageField(4, numberField(1, at)));
}

export type ServerFrame =
    | { kind: 'hello'; heartbeatMs: number; subscribed: string }
    | { kind: 'event'; sub: string; topic: string; offset: number; type: string; payload: string; at: number }
    | { kind: 'pong' }
    | { kind: 'error'; sub: string; code: number; message: string }
    | { kind: 'unknown' };

type Field = { field: number; wire: number; number: number; bytes: Uint8Array };

function fields(buffer: Uint8Array): Field[] {
    const out: Field[] = [];
    let at = 0;

    const read = (): number => {
        let value = 0;
        let scale = 1;

        while (at < buffer.length) {
            const byte = buffer[at++];
            value += (byte & 0x7f) * scale;
            scale *= 0x80;

            if (byte < 0x80) {
                break;
            }
        }

        return value;
    };

    while (at < buffer.length) {
        const key = read();
        const field = Math.floor(key / 8);
        const wire = key % 8;

        if (wire === 0) {
            out.push({ field, wire, number: read(), bytes: new Uint8Array() });
        } else if (wire === 2) {
            const length = read();
            out.push({ field, wire, number: 0, bytes: buffer.subarray(at, at + length) });
            at += length;
        } else if (wire === 1) {
            at += 8;
        } else if (wire === 5) {
            at += 4;
        } else {
            break;
        }
    }

    return out;
}

const str = (list: Field[], field: number) => {
    const found = list.find((item) => item.field === field);

    return found ? utf8.decode(found.bytes) : '';
};

const num = (list: Field[], field: number) => list.find((item) => item.field === field)?.number ?? 0;

export function decodeServerFrame(buffer: Uint8Array): ServerFrame {
    const top = fields(buffer)[0];

    if (!top) {
        return { kind: 'unknown' };
    }

    const inner = fields(top.bytes);

    if (top.field === 1) {
        return { kind: 'hello', heartbeatMs: num(inner, 2), subscribed: str(inner, 3) };
    }

    if (top.field === 2) {
        const event = fields(inner.find((item) => item.field === 2)?.bytes ?? new Uint8Array());

        return { kind: 'event', sub: str(inner, 1), topic: str(event, 1), offset: num(event, 3), type: str(event, 4), payload: str(event, 5), at: num(event, 7) };
    }

    if (top.field === 3) {
        return { kind: 'pong' };
    }

    if (top.field === 4) {
        return { kind: 'error', sub: str(inner, 1), code: num(inner, 2), message: str(inner, 3) };
    }

    return { kind: 'unknown' };
}
