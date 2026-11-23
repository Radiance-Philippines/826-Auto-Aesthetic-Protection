import { AccessibilityInfo } from 'react-native';
import { HEADER } from '@826/core';
import { LOGO_POINTS } from './logoPoints';

/*
 * The opening animation, as pure maths. introFrame(t, layout) says where everything is at second t; the overlay
 * (src/ui/Intro.tsx) samples it into Animated interpolations, and the preview filmstrip draws the same frames.
 *
 * White Porsche (3/4 front, mirrored so the nose leads left) drives in from the right and gets bigger as it nears,
 * brakes hard (nose dip + stage shake) and does a burnout: dense grey-white smoke boils up from the tyres. Then every puff
 * flows to its own point inside the 826 glyphs (LOGO_POINTS), shrinking to a small dot, so the smoke itself draws the mark in
 * grey. Only once it is formed and has held for a moment do the real colours cross-fade in. The car launches off to the left,
 * the coloured mark glides to the header and the dark overlay fades away.
 */

/** Seconds. `hold` is where the mark waits (settled, centred) until the app is ready; `end` is the last frame. */
export const PHASE = {
    cruiseEnd: 0.7, // constant speed until here, then the hard brake
    stop: 1.0, // the car is still: nose dip rebounds, stage shakes, burnout starts
    smokeStart: 1.0,
    smokeLast: 1.9, // the last puff is born at the tyres
    wobbleEnd: 2.3,
    formStart: 2.0, // the first puff leaves the cloud for its point
    launch: 2.35, // the car accelerates away (overlaps the formation)
    formed: 3.1, // the last puff has arrived: the smoke-grey 826 is complete
    exited: 3.15,
    colorIn: 3.35, // after a 0.25 s hold on the grey mark the colours start to come in
    colorSolid: 3.85, // 0.5 s cross-fade; the remaining puffs dissolve over the same time
    hold: 3.9,
    glideEnd: 4.5,
    bgFadeStart: 4.5, // only after the mark has landed on the real header logo, so two marks are never on screen
    end: 4.85,
} as const;
/** Reduced motion: no car, no smoke, the mark fades in on the header spot, then the screen */
export const REDUCED_FADE = 0.3;
/** One puff per target point */
export const PUFFS = LOGO_POINTS.length;
/** Native size of one smoke circle; the frame's `size` scales it */
export const PUFF_BASE = 128;

export type Spot = { x: number; y: number; w: number; h: number };
export type Layout = { W: number; H: number; spot: Spot };

/** The real header logo (see lib/layout.ts): Screen's padding, safe-area top, Brand's size. */
export function headerLogoSpot(insetTop: number, signedIn: boolean): Spot {
    const h = HEADER.brandHeight;

    return { x: HEADER.padX, y: signedIn ? insetTop + HEADER.topGap : HEADER.signInTop, w: h * HEADER.brandRatio, h };
}

export const carSize = (W: number) => {
    const w = Math.min(W * 0.86, 340);

    return { w, h: (w * 272) / 600 };
};
export const logoSize = (W: number) => {
    const w = Math.min(W * 0.66, 300);

    return { w, h: w / HEADER.brandRatio };
};
const clamp01 = (x: number) => Math.min(1, Math.max(0, x));
const smooth = (a: number, b: number, t: number) => {
    const x = clamp01((t - a) / (b - a));

    return x * x * (3 - 2 * x);
};
const lerp = (a: number, b: number, p: number) => a + (b - a) * p;
const inOut = (x: number) => (x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2);
const TAU = Math.PI * 2;

/** The stage: car rest pose, mark centre, smoke origins */
function stage({ W, H }: Layout) {
    const car = carSize(W);

    return { car, cx: W / 2, cy: H * 0.62, logo: logoSize(W), lx: W / 2, ly: H * 0.37 };
}

/** Every second puff is drawn in front of the car, the rest behind it (depth) */
export const puffInFront = (i: number) => i % 2 === 0;

export type Puff = { x: number; y: number; size: number; opacity: number };

type PuffSeed = { born: number; depart: number; arrive: number; target: number; ox: number; oy: number; ex: number; max: number; peak: number; hold: number; dot: number; sway: number; phase: number; swirl: number };

function rng(seed: number) {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Unit-space seeds (0..1 offsets); deterministic, the same for every layout. Each puff owns one logo point (a seeded shuffle). */
const SEEDS: PuffSeed[] = (() => {
    const r = rng(826);
    const order = Array.from({ length: PUFFS }, (_, i) => i);
    for (let i = PUFFS - 1; i > 0; i--) {
        const j = Math.floor(r() * (i + 1));
        [order[i], order[j]] = [order[j], order[i]];
    }

    return Array.from({ length: PUFFS }, (_, i) => {
        const born = PHASE.smokeStart + (i / (PUFFS - 1)) * (PHASE.smokeLast - PHASE.smokeStart) + (r() - 0.5) * 0.04;
        const depart = PHASE.formStart + r() * 0.45;

        return {
            born: Math.max(PHASE.smokeStart, born),
            depart,
            arrive: Math.min(PHASE.formed, depart + 0.58 + r() * 0.1),
            target: order[i],
            ox: r() - 0.5,
            oy: r(),
            ex: r() - 0.5,
            max: 0.4 + r() * 0.6,
            peak: 0.22 + r() * 0.28,
            hold: 0.45 + r() * 0.25,
            dot: 0.85 + r() * 0.3,
            sway: 10 + r() * 26,
            phase: r() * TAU,
            swirl: r() < 0.5 ? -1 : 1,
        };
    });
})();

/** Which LOGO_POINTS index puff i flows to */
export const puffTarget = (i: number) => SEEDS[i].target;

/** Ease-out with a slight overshoot (the puff slips just past its point and settles back) */
const outBack = (u: number) => 1 + 2 * Math.pow(u - 1, 3) + 1 * Math.pow(u - 1, 2);

/** Burnout cloud: born across the low body at the tyres, boils up and out, grows, sways */
function billow(s: PuffSeed, t: number, layout: Layout) {
    const { car, cx, cy } = stage(layout);
    const { H } = layout;
    const age = t - s.born;
    const a = smooth(0, 1.4, age);
    const x = cx + s.ox * 0.85 * car.w + (s.ex * 0.55 + 0.08) * car.w * a + Math.sin(age * 4 + s.phase) * s.sway * a;
    const y = cy + (0.28 + 0.14 * s.oy) * car.h - H * (0.015 + 0.05 * s.max) * a - 8 * age;
    const grow = 0.25 + 0.75 * (1 - Math.pow(1 - clamp01(age / 0.9), 2));

    return { x, y, size: (60 + s.max * 120) * grow, opacity: s.peak * smooth(0, 0.25, age) };
}

/**
 * Puff i at second t. Born at the tyres, billows as dense smoke; at its own `depart` it flows (ease-out, a little swirl and overshoot)
 * to its point inside the 826 glyphs and shrinks to a small soft dot; after the colours come in it dissolves.
 */
export function puffAt(i: number, t: number, layout: Layout): Puff {
    const s = SEEDS[i];
    const { lx, ly, logo } = stage(layout);

    if (t <= s.born || t >= PHASE.colorSolid) {
        return { x: lx, y: ly, size: 0, opacity: 0 };
    }

    const gone = 1 - smooth(PHASE.colorIn, PHASE.colorSolid, t);

    if (t <= s.depart) {
        const b = billow(s, t, layout);

        return { x: b.x, y: b.y, size: b.size, opacity: b.opacity };
    }

    const from = billow(s, s.depart, layout);
    const [px, py] = LOGO_POINTS[s.target];
    const tx = lx + (px - 0.5) * logo.w;
    const ty = ly + (py - 0.5) * logo.h;
    const u = clamp01((t - s.depart) / (s.arrive - s.depart));
    const e = outBack(u);
    const dx = tx - from.x;
    const dy = ty - from.y;
    const bend = s.swirl * Math.hypot(dx, dy) * 0.16 * Math.sin(Math.PI * u);
    const len = Math.hypot(dx, dy) || 1;
    const dotSize = logo.w * 0.065 * s.dot;

    return {
        x: from.x + dx * e - (dy / len) * bend,
        y: from.y + dy * e + (dx / len) * bend,
        size: lerp(from.size, dotSize, smooth(0, 0.6, u)),
        opacity: lerp(from.opacity, s.hold, smooth(0.5, 1, u)) * gone,
    };
}

export type Frame = {
    /** Dark overlay background, 0..1 */
    bg: number;
    /** Whole-stage shake in px */
    shake: { x: number; y: number };
    /** Car centre in px, scale of the full-size car, degrees (negative = nose down), visible */
    car: { x: number; y: number; scale: number; rotate: number; opacity: number };
    puffs: Puff[];
    /** The smoke-grey silhouette of the mark under the puffs (same box as `logo`), 0..1; it is only a hint, the puffs draw the shape */
    ghost: number;
    /** Mark centre in px, scale of its full-size box, opacity 0..1: the real colours, which come in only after the smoke has formed it */
    logo: { x: number; y: number; scale: number; opacity: number };
};

/** Car travel progress 0..1: constant speed, then a constant-deceleration stop (the hard brake) */
function drive(t: number) {
    const { cruiseEnd, stop } = PHASE;
    const d = stop - cruiseEnd;
    const total = cruiseEnd + d / 2;

    if (t <= cruiseEnd) {
        return t / total;
    }

    const s = Math.min(t - cruiseEnd, d);

    return (cruiseEnd + s - (s * s) / (2 * d)) / total;
}

export function introFrame(t: number, layout: Layout): Frame {
    const { W, H, spot } = layout;
    const { car, cx, cy, logo, lx, ly } = stage(layout);
    t = Math.min(Math.max(t, 0), PHASE.end);

    // Car: enters (far right, smaller, higher) to the rest pose, still, then launches left
    const p = clamp01(drive(t));
    const startX = W + car.w * 0.3;
    const startY = H * 0.5;
    let x = lerp(startX, cx, p);
    let y = lerp(startY, cy, p);
    let scale = lerp(0.5, 1, p);
    let rotate = 0;

    // Hard brake: nose dips (front is the left), rebounds as a damped wobble
    const since = t - PHASE.stop;
    const dip = t < PHASE.stop ? smooth(PHASE.cruiseEnd, PHASE.stop, t) : Math.exp(-since * 9) * Math.cos(since * 18);
    rotate += -2.6 * dip;
    y += 5 * dip;

    // Burnout: quick high-frequency wobble held in place
    const wob = smooth(PHASE.stop, PHASE.stop + 0.15, t) * (1 - smooth(PHASE.wobbleEnd - 0.05, PHASE.wobbleEnd + 0.05, t));
    x += 1.6 * wob * Math.sin(TAU * 21 * t);
    y += 1.0 * wob * Math.sin(TAU * 17 * t + 0.7);
    rotate += 0.5 * wob * Math.sin(TAU * 13 * t + 1.3);

    // Launch: a short squat (nose up), then away to the left, growing toward the viewer
    const go = clamp01((t - PHASE.launch) / (PHASE.exited - PHASE.launch));
    if (go > 0) {
        const away = Math.pow(go, 2.2);
        x -= (cx + car.w * 1.4) * away;
        y += H * 0.03 * away;
        scale *= 1 + 0.25 * away;
        rotate += 2.2 * Math.sin(Math.PI * Math.min(1, go * 2.5)) * (1 - smooth(0.4, 1, go));
    }

    const shakeAmp = 6 * Math.exp(-Math.max(0, t - 0.95) * 10) * (t >= 0.95 ? 1 : 0) + 1.2 * wob;

    // Mark: stays centred while the smoke forms it and the colours come in, waits, then glides (ease in-out) onto the header spot
    const pos = inOut(clamp01((t - PHASE.hold) / (PHASE.glideEnd - PHASE.hold)));
    const colour = inOut(clamp01((t - PHASE.colorIn) / (PHASE.colorSolid - PHASE.colorIn)));
    const ghost = 0.9 * smooth(PHASE.formStart + 0.5, PHASE.formed, t) * (1 - colour);

    return {
        bg: 1 - smooth(PHASE.bgFadeStart, PHASE.end, t),
        shake: { x: shakeAmp * Math.sin(TAU * 18 * t), y: shakeAmp * 0.7 * Math.sin(TAU * 23 * t + 1) },
        car: { x, y, scale, rotate, opacity: go >= 1 || t <= 0 ? 0 : 1 },
        puffs: SEEDS.map((_, i) => puffAt(i, t, layout)),
        ghost,
        logo: { x: lerp(lx, spot.x + spot.w / 2, pos), y: lerp(ly, spot.y + spot.h / 2, pos), scale: lerp(1, spot.w / logo.w, pos), opacity: colour },
    };
}

/** Reduced motion: no car, no smoke. The mark fades in on the header spot over REDUCED_FADE seconds. */
export function reducedFrame(t: number, layout: Layout) {
    const { spot } = layout;

    return { bg: 1, logo: { x: spot.x + spot.w / 2, y: spot.y + spot.h / 2, scale: spot.w / logoSize(layout.W).w, opacity: clamp01(t / REDUCED_FADE) } };
}

/**
 * Everything the overlay animates, sampled from introFrame into keyframe arrays (inputRange = milliseconds).
 * Car, shake, mark and background are sampled every 1/120 s (the wobble is 21 Hz); each puff only over its own life (born to dissolved), every 1/30 s.
 */
export function sampleIntro(layout: Layout) {
    const fine: number[] = [];
    for (let ms = 0; ms < PHASE.end * 1000; ms += 1000 / 120) {
        fine.push(ms);
    }
    fine.push(PHASE.end * 1000);
    const frames = fine.map((ms) => introFrame(ms / 1000, layout));
    const col = <T>(pick: (f: Frame) => T) => frames.map(pick);
    const puffs = SEEDS.map((s, i) => {
        const from = Math.max(0, s.born - 1 / 30);
        const to = PHASE.colorSolid + 1 / 30;
        const times: number[] = [];
        for (let sec = from; sec < to; sec += 1 / 30) {
            times.push(sec);
        }
        times.push(to);
        const list = times.map((sec) => puffAt(i, sec, layout));

        return { ms: times.map((sec) => sec * 1000), x: list.map((q) => q.x - PUFF_BASE / 2), y: list.map((q) => q.y - PUFF_BASE / 2), scale: list.map((q) => q.size / PUFF_BASE), opacity: list.map((q) => q.opacity) };
    });

    return {
        ms: fine,
        bg: col((f) => f.bg),
        shakeX: col((f) => f.shake.x),
        shakeY: col((f) => f.shake.y),
        carX: col((f) => f.car.x - carSize(layout.W).w / 2),
        carY: col((f) => f.car.y - carSize(layout.W).h / 2),
        carScale: col((f) => f.car.scale),
        carRotate: col((f) => f.car.rotate),
        carOpacity: col((f) => f.car.opacity),
        logoX: col((f) => f.logo.x - logoSize(layout.W).w / 2),
        logoY: col((f) => f.logo.y - logoSize(layout.W).h / 2),
        logoScale: col((f) => f.logo.scale),
        logoOpacity: col((f) => f.logo.opacity),
        ghost: col((f) => f.ghost),
        puffs,
    };
}

/** Once per cold start: in memory on purpose, a hot reload of a tab never re-mounts the gate's flag */
export const introLaunch = { seen: false };

export type IntroMode = 'full' | 'reduced';

/** First call per launch returns how to play, later calls null. Reads the phone's remove-animations setting itself. */
export async function decideIntro(): Promise<IntroMode | null> {
    if (introLaunch.seen) {
        return null;
    }
    introLaunch.seen = true;

    try {
        return (await AccessibilityInfo.isReduceMotionEnabled()) ? 'reduced' : 'full';
    } catch {
        return 'full';
    }
}
