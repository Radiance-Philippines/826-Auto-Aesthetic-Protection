// A tiny stand-in for Radiance Flow, for the browser tests only (no packages, plain node).
//   - POST /radiance.flow.v1.Events/Publish and /PublishBatch (Connect JSON, Bearer app token): what the app publishes
//   - GET /ws: the WebSocket browsers listen on (binary protobuf ClientFrame / ServerFrame from live.proto). A
//     subscribe is accepted only with a ticket signed the way docs/APPS.md says (HMAC-SHA256 over the body text, not
//     expired) that lists the topic: so these tests also check the app's tickets and topics from the receiving side.
//   - GET /_test/state: counters the tests read. POST /_test/drop: closes every socket (a network blip).
// This is its own small protobuf reader, apart from the browser client's, so each checks the other.
import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
import http from 'node:http';

const PORT = Number(process.env.E2E_FLOW_PORT ?? 8052);
const TOKEN = process.env.E2E_FLOW_TOKEN ?? 'e2e-app-token';
const SECRET = process.env.E2E_FLOW_SECRET ?? 'e2e-ticket-secret';

const sockets = new Set();
const offsets = new Map();
const history = new Map();
const state = { published: 0, subscribes: 0, refused: 0, topics: new Set() };

// ---- protobuf (just what live.proto needs) --------------------------------------------------------------------
const varint = (value) => {
    const out = [];
    let rest = value;
    while (rest >= 0x80) {
        out.push((rest % 0x80) | 0x80);
        rest = Math.floor(rest / 0x80);
    }
    out.push(rest);
    return out;
};
const field = (number, bytes) => [...varint(number * 8 + 2), ...varint(bytes.length), ...bytes];
const text = (number, value) => (value === '' ? [] : field(number, [...Buffer.from(value)]));
const int = (number, value) => (value === 0 ? [] : [...varint(number * 8), ...varint(value)]);

function parse(buffer) {
    const out = [];
    let at = 0;
    const read = () => {
        let value = 0;
        let scale = 1;
        while (at < buffer.length) {
            const byte = buffer[at++];
            value += (byte & 0x7f) * scale;
            scale *= 0x80;
            if (byte < 0x80) break;
        }
        return value;
    };
    while (at < buffer.length) {
        const key = read();
        const number = Math.floor(key / 8);
        if (key % 8 === 0) out.push({ number, value: read() });
        else if (key % 8 === 2) {
            const length = read();
            out.push({ number, bytes: buffer.subarray(at, at + length) });
            at += length;
        } else break;
    }
    return out;
}
const str = (list, number) => (list.find((item) => item.number === number)?.bytes ?? Buffer.alloc(0)).toString();

const hello = (subscribed = '') => Buffer.from(field(1, [...text(1, 'fake-flow'), ...int(2, 30000), ...text(3, subscribed)]));
const pong = () => Buffer.from(field(3, int(1, Date.now())));
const failure = (sub, code, message) => Buffer.from(field(4, [...text(1, sub), ...int(2, code), ...text(3, message)]));
const eventFrame = (sub, topic, offset, type, payload) => Buffer.from(field(2, [...text(1, sub), ...field(2, [...text(1, topic), ...int(3, offset), ...text(4, type), ...field(5, [...payload]), ...int(7, Date.now())])]));

// ---- WebSocket (RFC 6455, binary frames only) ------------------------------------------------------------------
function send(socket, payload) {
    const length = payload.length;
    const head = length < 126 ? Buffer.from([0x82, length]) : Buffer.from([0x82, 126, length >> 8, length & 0xff]);
    socket.write(Buffer.concat([head, payload]));
}

function frames(socket, onFrame) {
    let pending = Buffer.alloc(0);
    socket.on('data', (chunk) => {
        pending = Buffer.concat([pending, chunk]);
        for (;;) {
            if (pending.length < 2) return;
            const opcode = pending[0] & 0x0f;
            let length = pending[1] & 0x7f;
            let at = 2;
            if (length === 126) {
                if (pending.length < 4) return;
                length = pending.readUInt16BE(2);
                at = 4;
            } else if (length === 127) {
                if (pending.length < 10) return;
                length = Number(pending.readBigUInt64BE(2));
                at = 10;
            }
            const masked = (pending[1] & 0x80) !== 0;
            if (pending.length < at + (masked ? 4 : 0) + length) return;
            const mask = masked ? pending.subarray(at, at + 4) : null;
            at += masked ? 4 : 0;
            const body = Buffer.from(pending.subarray(at, at + length));
            if (mask) for (let i = 0; i < body.length; i++) body[i] ^= mask[i % 4];
            pending = pending.subarray(at + length);
            if (opcode === 0x8) return socket.end();
            if (opcode === 0x9) {
                socket.write(Buffer.concat([Buffer.from([0x8a, body.length]), body]));
            } else if (opcode === 0x2) onFrame(body);
        }
    });
}

function ticketClaims(ticket) {
    const [body, signature] = String(ticket).split('.');
    if (!body || !signature) return null;
    const expected = createHmac('sha256', SECRET).update(body).digest('base64url');
    if (expected.length !== signature.length || !timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) return null;
    const claims = JSON.parse(Buffer.from(body, 'base64url').toString());
    return claims.e > Date.now() / 1000 ? claims : null;
}

const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://x');

    if (request.method === 'GET' && url.pathname === '/_test/state') {
        response.setHeader('Content-Type', 'application/json');
        return response.end(JSON.stringify({ ...state, topics: [...state.topics], sockets: sockets.size }));
    }

    if (request.method === 'POST' && url.pathname === '/_test/drop') {
        for (const live of sockets) live.socket.destroy();
        response.statusCode = 204;
        return response.end();
    }

    if (request.method === 'GET' && url.pathname === '/health') {
        return response.end('ok');
    }

    if (request.method === 'POST' && url.pathname.startsWith('/radiance.flow.v1.Events/Publish')) {
        let raw = '';
        request.on('data', (chunk) => (raw += chunk));
        request.on('end', () => {
            response.setHeader('Content-Type', 'application/json');

            if (request.headers.authorization !== `Bearer ${TOKEN}`) {
                response.statusCode = 401;
                return response.end(JSON.stringify({ code: 'unauthenticated', message: 'bad token' }));
            }

            const body = JSON.parse(raw || '{}');
            const events = body.events ?? (body.event ? [body.event] : []);
            const taken = [];

            for (const event of events) {
                const offset = (offsets.get(event.topic) ?? 0) + 1;
                offsets.set(event.topic, offset);
                const payload = Buffer.from(event.payload ?? '', 'base64');
                const frame = { offset, type: event.type, payload };
                history.set(event.topic, [...(history.get(event.topic) ?? []), frame].slice(-200));
                state.published++;
                state.topics.add(event.topic);
                taken.push(offset);

                for (const live of sockets) {
                    for (const [sub, topic] of live.subs) {
                        if (topic === event.topic) send(live.socket, eventFrame(sub, topic, offset, event.type, payload));
                    }
                }
            }

            response.end(JSON.stringify({ taken: taken.length, offsets: taken.map(String) }));
        });
        return;
    }

    response.statusCode = 404;
    response.end();
});

server.on('upgrade', (request, socket) => {
    if (new URL(request.url, 'http://x').pathname !== '/ws') return socket.destroy();

    const accept = createHash('sha1')
        .update(`${request.headers['sec-websocket-key']}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`)
        .digest('base64');
    socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);

    const live = { socket, subs: new Map() };
    sockets.add(live);
    socket.on('close', () => sockets.delete(live));
    socket.on('error', () => sockets.delete(live));
    send(socket, hello());

    frames(socket, (body) => {
        const top = parse(body)[0];
        if (!top?.bytes) return;
        const inner = parse(top.bytes);

        if (top.number === 4) return send(socket, pong());
        if (top.number !== 1) return;

        const id = str(inner, 1);
        const topic = str(inner, 3);
        const after = inner.find((item) => item.number === 5)?.value ?? 0;
        const claims = ticketClaims(str(inner, 2));

        if (!claims) {
            state.refused++;
            return send(socket, failure(id, 401, 'ticket'));
        }

        if (!claims.t.includes(topic)) {
            state.refused++;
            return send(socket, failure(id, 403, 'topic not allowed'));
        }

        state.subscribes++;
        live.subs.set(id, topic);
        send(socket, hello(id));

        for (const frame of history.get(topic) ?? []) {
            if (frame.offset > after && after > 0) send(socket, eventFrame(id, topic, frame.offset, frame.type, frame.payload));
        }
    });
});

server.listen(PORT, '127.0.0.1');
