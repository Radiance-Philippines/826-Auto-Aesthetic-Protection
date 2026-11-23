import { AccessibilityInfo } from 'react-native';
import { HEADER } from './layout';

/*
 * The opening animation, as pure maths. introFrame(t, layout) says where everything is at second t; the overlay
 * (src/ui/Intro.tsx) samples it into Animated interpolations, and the preview filmstrip draws the same frames.
 *
 * White Porsche (3/4 front, mirrored so the nose leads left) drives in from the right and gets bigger as it nears,
 * brakes hard (nose dip + stage shake), does a burnout (wobble + tyre smoke), the smoke gathers into the 826 mark,
 * the car launches off to the left, the mark glides to the header and the dark overlay fades away.
 */

/** Seconds. `hold` is where the mark waits (settled, centred) until the app is ready; `end` is the last frame. */
export const PHASE = {
    cruiseEnd: 0.7, // constant speed until here, then the hard brake
    stop: 1.0, // the car is still: nose dip rebounds, stage shakes, burnout starts
    smokeStart: 1.05,
    smokeLast: 2.4, // the last puff is born
    wobbleEnd: 2.85,
    logoIn: 2.5, // the mark starts to emerge from the densest smoke
    logoSolid: 3.2,
    smokeGone: 3.5,
    launch: 2.9, // the car accelerates away
    exited: 3.8,
    hold: 3.85,
    glideEnd: 4.5,
    bgFadeStart: 4.5, // only after the mark has landed on the real header logo, so two marks are never on screen
    end: 4.85,
} as const;
/** Reduced motion: no car, no smoke, the mark fades in on the header spot, then the screen */
export const REDUCED_FADE = 0.3;
export const PUFFS = 44;
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

type PuffSeed = { born: number; life: number; rear: boolean; ox: number; oy: number; ex: number; ey: number; max: number; peak: number; sway: number; phase: number };

function rng(seed: number) {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Unit-space seeds (0..1 offsets); deterministic, the same for every layout */
const SEEDS: PuffSeed[] = (() => {
    const r = rng(826);

    return Array.from({ length: PUFFS }, (_, i) => {
        const rear = r() < 0.75;
        const born = PHASE.smokeStart + Math.pow(i / (PUFFS - 1), 0.8) * (PHASE.smokeLast - PHASE.smokeStart) + (r() - 0.5) * 0.06;

        return {
            born,
            life: PHASE.smokeGone - born + r() * 0.1,
            rear,
            ox: r() - 0.5,
            oy: r() - 0.5,
            ex: r() - 0.5,
            ey: r() - 0.5,
            max: 0.4 + r() * 0.6,
            peak: 0.14 + r() * 0.3,
            sway: 10 + r() * 22,
            phase: r() * TAU,
        };
    });
})();

/** Puff i at second t. Born at the tyres, billows low and grows, then the whole cloud travels up into a band around the mark and thins away as the mark takes its place. */
export function puffAt(i: number, t: number, layout: Layout): Puff {
    const s = SEEDS[i];
    const { car, cx, cy, lx, ly } = stage(layout);
    const { W, H } = layout;
    const age = t - s.born;

    if (age <= 0 || age >= s.life) {
        return { x: cx, y: cy, size: 0, opacity: 0 };
    }

    const sx = cx + (s.rear ? 0.4 : 0.08) * car.w + s.ox * 0.1 * car.w;
    const sy = cy + 0.36 * car.h + s.oy * 0.1 * car.h;
    // Burnout billow: out and a little up from the tyres, drifting back along the car
    const a = smooth(0, 1.7, age);
    const bx = sx - (0.35 + s.ex * 0.9) * car.w * a + Math.sin(age * 4 + s.phase) * s.sway * a;
    const by = sy - H * (0.04 + 0.1 * s.max) * a;
    // Condense: the cloud travels to its slot around the mark
    const pull = smooth(1.9, PHASE.logoSolid - 0.05, t);
    const x = lerp(bx, lx + s.ex * W * 0.62, pull);
    const y = lerp(by, ly + s.ey * H * 0.09, pull);
    const grow = 0.3 + 0.7 * (1 - Math.pow(1 - clamp01(age / 1.1), 2));
    const gone = 1 - smooth(2.9, PHASE.smokeGone, t);

    return { x, y, size: (60 + s.max * 150) * grow, opacity: s.peak * smooth(0, 0.2, age) * gone };
}

export type Frame = {
    /** Dark overlay background, 0..1 */
    bg: number;
    /** Whole-stage shake in px */
    shake: { x: number; y: number };
    /** Car centre in px, scale of the full-size car, degrees (negative = nose down), visible */
    car: { x: number; y: number; scale: number; rotate: number; opacity: number };
    puffs: Puff[];
    /** Mark centre in px, scale of its full-size box, 0..1 */
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

    // Mark: emerges from the densest smoke, settles, waits, then glides (ease in-out) onto the header spot
    const pos = inOut(clamp01((t - PHASE.hold) / (PHASE.glideEnd - PHASE.hold)));
    const rise = smooth(PHASE.logoIn, PHASE.logoSolid, t);
    const emerge = lerp(0.72, 1.04, Math.sin((Math.PI / 2) * clamp01((t - PHASE.logoIn) / (PHASE.logoSolid - PHASE.logoIn))));
    const settle = lerp(emerge, 1, smooth(PHASE.logoSolid, PHASE.logoSolid + 0.3, t));
    const logoScale = lerp(settle, spot.w / logo.w, pos);

    return {
        bg: 1 - smooth(PHASE.bgFadeStart, PHASE.end, t),
        shake: { x: shakeAmp * Math.sin(TAU * 18 * t), y: shakeAmp * 0.7 * Math.sin(TAU * 23 * t + 1) },
        car: { x, y, scale, rotate, opacity: go >= 1 || t <= 0 ? 0 : 1 },
        puffs: SEEDS.map((_, i) => puffAt(i, t, layout)),
        logo: { x: lerp(lx, spot.x + spot.w / 2, pos), y: lerp(ly, spot.y + spot.h / 2, pos), scale: logoScale, opacity: rise },
    };
}

/** Reduced motion: no car, no smoke. The mark fades in on the header spot over REDUCED_FADE seconds. */
export function reducedFrame(t: number, layout: Layout) {
    const { spot } = layout;

    return { bg: 1, logo: { x: spot.x + spot.w / 2, y: spot.y + spot.h / 2, scale: spot.w / logoSize(layout.W).w, opacity: clamp01(t / REDUCED_FADE) } };
}

/**
 * Everything the overlay animates, sampled from introFrame into keyframe arrays (inputRange = milliseconds).
 * Car, shake, mark and background are sampled every 1/120 s (the wobble is 21 Hz); each puff only over its own life, every 1/30 s.
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
        const to = Math.min(PHASE.end, s.born + s.life + 1 / 30);
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
