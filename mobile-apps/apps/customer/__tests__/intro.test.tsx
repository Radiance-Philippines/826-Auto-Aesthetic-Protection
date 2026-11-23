import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { AccessibilityInfo, Animated } from 'react-native';
import { carSize, decideIntro, headerLogoSpot, introFrame, introLaunch, logoSize, PHASE, PUFFS, puffInFront, puffTarget, reducedFrame, REDUCED_FADE, sampleIntro, type Layout } from '@/lib/intro';
import { LOGO_POINTS } from '@/lib/logoPoints';
import { HEADER, ThemeProvider } from '@826/core';
import { Intro, IntroGate, SKIP_MS } from '@/ui/Intro';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

// The opening animation is paused: the gate renders nothing unless the build sets this flag
beforeAll(() => {
    process.env.EXPO_PUBLIC_INTRO = '1';
});
afterAll(() => {
    delete process.env.EXPO_PUBLIC_INTRO;
});

jest.mock('expo-splash-screen', () => ({ hideAsync: jest.fn(async () => {}), preventAutoHideAsync: jest.fn(async () => {}) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 47, bottom: 34, left: 0, right: 0 }) }));

const W = 390;
const H = 844;
const spot = headerLogoSpot(47, true);
const layout: Layout = { W, H, spot };
const at = (t: number) => introFrame(t, layout);
const puffTotal = (t: number) => at(t).puffs.reduce((sum, p) => sum + p.opacity, 0);
const alive = (t: number) => at(t).puffs.filter((p) => p.opacity > 0.01);

describe('introFrame phases', () => {
    it('has the phases in order, about 5 s long', () => {
        const order = [0, PHASE.cruiseEnd, PHASE.stop, PHASE.smokeStart, PHASE.smokeLast, PHASE.formStart, PHASE.launch, PHASE.formed, PHASE.exited, PHASE.colorIn, PHASE.colorSolid, PHASE.hold, PHASE.glideEnd, PHASE.end];
        expect(order).toEqual([...order].sort((x, y) => x - y));
        expect(PHASE.smokeStart).toBeGreaterThanOrEqual(PHASE.stop);
        expect(PHASE.launch).toBeGreaterThan(PHASE.wobbleEnd);
        expect(PHASE.colorIn - PHASE.formed).toBeGreaterThanOrEqual(0.25); // the grey mark is held before any colour
        expect(PHASE.colorSolid - PHASE.colorIn).toBeCloseTo(0.5);
        expect(PHASE.hold).toBeGreaterThanOrEqual(PHASE.exited);
        expect(PHASE.bgFadeStart).toBeGreaterThanOrEqual(PHASE.glideEnd);
        expect(PHASE.end).toBeGreaterThan(4.5);
        expect(PHASE.end).toBeLessThanOrEqual(5);
    });

    it('1. the car enters from the right edge, small and far, and grows toward the lower left', () => {
        const a = at(0.05).car;
        const b = at(0.4).car;
        const c = at(0.69).car;
        expect(a.x).toBeGreaterThan(W);
        expect(a.scale).toBeLessThan(0.6);
        expect(b.x).toBeLessThan(a.x);
        expect(c.x).toBeLessThan(b.x);
        expect(c.y).toBeGreaterThan(a.y);
        expect(c.scale).toBeGreaterThan(b.scale);
    });

    it('2. the brake is hard: fast, then still, with a nose dip and a stage shake', () => {
        const speed = (t: number) => Math.abs(at(t + 0.01).car.x - at(t).car.x) / 0.01;
        expect(speed(0.6)).toBeGreaterThan(speed(0.9) * 2);
        expect(Math.abs(at(PHASE.stop).car.x - W / 2)).toBeLessThan(1);
        const dips = [0.8, 0.9, 1.0, 1.1].map((t) => at(t).car.rotate);
        expect(Math.min(...dips)).toBeLessThan(-1);
        const shake = Math.max(...[0.96, 0.98, 1.0, 1.03, 1.06].map((t) => Math.hypot(at(t).shake.x, at(t).shake.y)));
        expect(shake).toBeGreaterThan(2);
        expect(Math.hypot(at(1.6).shake.x, at(1.6).shake.y)).toBeLessThan(2);
    });

    it('3. the burnout holds the car in place with a quick wobble', () => {
        const xs = Array.from({ length: 40 }, (_, i) => at(1.4 + i * 0.01).car.x);
        const spread = Math.max(...xs) - Math.min(...xs);
        expect(spread).toBeGreaterThan(0.5);
        expect(spread).toBeLessThan(5);
        expect(Math.abs(xs[0] - W / 2)).toBeLessThan(4);
    });

    it('3b. the burnout smoke is dense and comes from the tyres, not from a blob above the car', () => {
        const car = at(1.4).car;
        const carH = carSize(W).h;
        expect(alive(1.1).length).toBeGreaterThan(0);
        expect(puffTotal(1.8)).toBeGreaterThan(puffTotal(1.2));
        expect(alive(1.9).length).toBeGreaterThanOrEqual(80);
        // born low: at the tyres, below the car's middle
        const born = at(PHASE.smokeStart + 0.03).puffs.filter((p) => p.size > 0);
        expect(born.length).toBeGreaterThan(0);
        for (const p of born) {
            expect(p.y).toBeGreaterThan(car.y + carH * 0.2);
        }
        // the mass of the smoke sits at the car, spread across its width, and well below where the mark will be
        const live = alive(1.9);
        const cy = live.reduce((sum, p) => sum + p.y, 0) / live.length;
        expect(cy).toBeGreaterThan(H * 0.5);
        expect(Math.max(...live.map((p) => p.x)) - Math.min(...live.map((p) => p.x))).toBeGreaterThan(carSize(W).w * 0.6);
        expect(Math.max(...live.map((p) => p.size))).toBeGreaterThan(90);
        // some in front of the car, some behind
        expect(at(1.9).puffs.some((p, i) => p.size > 0 && puffInFront(i))).toBe(true);
        expect(at(1.9).puffs.some((p, i) => p.size > 0 && !puffInFront(i))).toBe(true);
        // no logo, no grey mark yet
        expect(at(1.9).ghost).toBe(0);
        expect(at(1.9).logo.opacity).toBe(0);
    });

    it('4. the smoke converges to the points inside the 826 glyphs and shrinks to small dots', () => {
        const { lx, ly, lw, lh } = { lx: W / 2, ly: H * 0.37, lw: logoSize(W).w, lh: logoSize(W).h };
        const f = at(PHASE.formed);
        expect(f.puffs).toHaveLength(LOGO_POINTS.length);
        // every puff sits exactly on its own point; together they use every point once
        const used = new Set<string>();
        for (const p of f.puffs) {
            const px = (p.x - lx) / lw + 0.5;
            const py = (p.y - ly) / lh + 0.5;
            const hit = LOGO_POINTS.findIndex(([x, y]) => Math.abs(x - px) < 1e-6 && Math.abs(y - py) < 1e-6);
            expect(hit).toBeGreaterThanOrEqual(0);
            used.add(String(hit));
            expect(p.size).toBeLessThan(lw * 0.1);
            expect(p.opacity).toBeGreaterThan(0.3);
        }
        expect(used.size).toBe(LOGO_POINTS.length);
        // on the way: the cloud is still big and far, later it is smaller and closer
        const meanDist = (t: number) => at(t).puffs.reduce((sum, p, i) => sum + Math.hypot(p.x - (lx + (LOGO_POINTS[puffTarget(i)][0] - 0.5) * lw), p.y - (ly + (LOGO_POINTS[puffTarget(i)][1] - 0.5) * lh)), 0) / PUFFS;
        expect(meanDist(PHASE.formStart)).toBeGreaterThan(meanDist(2.5));
        expect(meanDist(2.5)).toBeGreaterThan(meanDist(2.9));
        expect(meanDist(PHASE.formed)).toBeLessThan(1e-6);
        const size = (t: number) => at(t).puffs.reduce((m, p) => Math.max(m, p.size), 0);
        expect(size(PHASE.formed)).toBeLessThan(size(PHASE.formStart) / 2);
        // the soft grey silhouette comes in under them
        expect(at(PHASE.formStart).ghost).toBe(0);
        expect(at(PHASE.formed).ghost).toBeGreaterThan(0.5);
    });

    it('4b. the points are a clean set: inside the box, spread out, deterministic', () => {
        expect(LOGO_POINTS.length).toBeGreaterThanOrEqual(100);
        expect(LOGO_POINTS.length).toBeLessThanOrEqual(160);
        for (const [x, y] of LOGO_POINTS) {
            expect(x).toBeGreaterThan(0);
            expect(x).toBeLessThan(1);
            expect(y).toBeGreaterThan(0);
            expect(y).toBeLessThan(1);
        }
        const gap = Math.min(...LOGO_POINTS.flatMap(([x, y], i) => LOGO_POINTS.slice(i + 1).map(([u, v]) => Math.hypot((x - u) * 480, (y - v) * 134))));
        expect(gap).toBeGreaterThan(8);
    });

    it('4c. no colour until the smoke has formed the mark and held it; then a 0.5 s cross-fade', () => {
        for (const t of [0.5, 1.5, 2.2, 2.8, PHASE.formed, PHASE.colorIn]) {
            expect(at(t).logo.opacity).toBe(0);
        }
        // formed and held: the grey mark is fully there, still no colour
        const held = at(PHASE.colorIn - 0.02);
        expect(held.logo.opacity).toBe(0);
        expect(held.ghost).toBeGreaterThan(0.5);
        expect(PHASE.colorIn - PHASE.formed).toBeGreaterThanOrEqual(0.25);
        // cross-fade, ease in-out, the grey mark and the dots go as the colour arrives
        const mid = at((PHASE.colorIn + PHASE.colorSolid) / 2);
        expect(mid.logo.opacity).toBeCloseTo(0.5, 1);
        expect(at(PHASE.colorIn + 0.1).logo.opacity).toBeLessThan(0.2);
        expect(at(PHASE.colorSolid).logo.opacity).toBe(1);
        expect(mid.ghost).toBeLessThan(held.ghost);
        expect(at(PHASE.colorSolid).ghost).toBe(0);
        expect(puffTotal(PHASE.colorSolid)).toBe(0);
        expect(puffTotal(PHASE.colorIn + 0.2)).toBeLessThan(puffTotal(PHASE.colorIn));
        // the coloured mark stays in the same place while the colours come in
        expect(at(PHASE.colorSolid).logo.x).toBeCloseTo(W / 2);
        expect(at(PHASE.colorSolid).logo.scale).toBeCloseTo(1);
    });

    it('5. the car launches off to the left edge (overlapping the formation) and is gone before the colours come in', () => {
        expect(PHASE.launch).toBeLessThan(PHASE.formed);
        expect(at(PHASE.launch - 0.05).car.x).toBeGreaterThan(W / 2 - 6);
        expect(at(2.8).car.x).toBeLessThan(at(2.5).car.x);
        expect(at(PHASE.exited - 0.02).car.x - (carSize(W).w / 2) * at(PHASE.exited - 0.02).car.scale).toBeLessThan(0);
        expect(at(PHASE.exited).car.opacity).toBe(0);
        expect(at(PHASE.exited - 0.3).car.opacity).toBe(1);
        expect(PHASE.exited).toBeLessThanOrEqual(PHASE.colorIn);
    });

    it('6. the mark settles, waits centred, then lands exactly on the header logo', () => {
        const hold = at(PHASE.hold);
        expect(hold.logo.x).toBeCloseTo(W / 2);
        expect(hold.logo.y).toBeCloseTo(H * 0.37);
        expect(hold.logo.scale).toBeCloseTo(1);
        expect(hold.logo.opacity).toBe(1);
        expect(hold.ghost).toBe(0);
        expect(hold.bg).toBe(1);
        const end = at(PHASE.end);
        expect(end.logo.x).toBeCloseTo(spot.x + spot.w / 2);
        expect(end.logo.y).toBeCloseTo(spot.y + spot.h / 2);
        expect(end.logo.scale * logoSize(W).w).toBeCloseTo(spot.w);
        expect(end.logo.opacity).toBe(1);
        expect(end.bg).toBe(0);
        const landed = at(PHASE.glideEnd);
        expect(landed.logo.x).toBeCloseTo(spot.x + spot.w / 2);
        // the background stays until the mark is on the header: never two marks on screen
        expect(at(PHASE.glideEnd - 0.05).bg).toBe(1);
        // ease in-out: slow, fast, slow
        const mid = (PHASE.hold + PHASE.glideEnd) / 2;
        const d = (t: number) => Math.abs(at(t + 0.02).logo.y - at(t).logo.y);
        expect(d(mid)).toBeGreaterThan(d(PHASE.hold + 0.02) * 3);
        expect(d(mid)).toBeGreaterThan(d(PHASE.glideEnd - 0.04) * 3);
    });

    it('is deterministic and clamps outside the timeline', () => {
        expect(at(2.2)).toEqual(at(2.2));
        expect(at(-1)).toEqual(at(0));
        expect(at(99)).toEqual(at(PHASE.end));
    });

    it('is built for the real header: Screen padding, the safe area and Brand size', () => {
        expect(headerLogoSpot(47, true)).toEqual({ x: HEADER.padX, y: 47 + HEADER.topGap, w: HEADER.brandHeight * HEADER.brandRatio, h: HEADER.brandHeight });
        expect(headerLogoSpot(47, false).y).toBe(HEADER.signInTop);
        expect(HEADER).toMatchObject({ padX: 20, topGap: 12, signInTop: 48, brandHeight: 14 });
    });

    it('samples into keyframes that end on the last frame', () => {
        const k = sampleIntro(layout);
        expect(k.puffs).toHaveLength(PUFFS);
        expect(k.ms.every((ms, i) => i === 0 || ms > k.ms[i - 1])).toBe(true);
        expect(k.ms[k.ms.length - 1]).toBeCloseTo(PHASE.end * 1000);
        const last = at(PHASE.end);
        expect(k.ghost).toHaveLength(k.ms.length);
        expect(k.logoX[k.logoX.length - 1]).toBeCloseTo(last.logo.x - logoSize(W).w / 2);
        expect(k.bg[k.bg.length - 1]).toBe(0);
        for (const p of k.puffs) {
            expect(p.ms.every((ms, i) => i === 0 || ms > p.ms[i - 1])).toBe(true);
            expect(p.opacity[0]).toBe(0);
        }
    });
});

describe('reduced motion frame', () => {
    it('has no car and no smoke: only the mark, fading in on the header spot', () => {
        expect(Object.keys(reducedFrame(0, layout)).sort()).toEqual(['bg', 'logo']);
        expect(reducedFrame(0, layout).logo.opacity).toBe(0);
        expect(reducedFrame(REDUCED_FADE, layout).logo.opacity).toBe(1);
        expect(REDUCED_FADE).toBe(0.3);
        expect(reducedFrame(REDUCED_FADE, layout).logo.x).toBe(spot.x + spot.w / 2);
    });
});

describe('play once per cold start', () => {
    afterEach(() => {
        introLaunch.seen = false;
        jest.restoreAllMocks();
    });

    it('decides once, and reads the remove-animations setting', async () => {
        jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(false);
        await expect(decideIntro()).resolves.toBe('full');
        await expect(decideIntro()).resolves.toBeNull();
        introLaunch.seen = false;
        jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
        await expect(decideIntro()).resolves.toBe('reduced');
    });
});

describe('overlay', () => {
    let renderer: ReactTestRenderer | undefined;
    type Job = { config: { toValue: number; duration: number }; done: (result: { finished: boolean }) => void };
    let queue: Job[] = [];
    const mount = async (element: React.ReactElement) => {
        await act(async () => {
            renderer = create(<ThemeProvider scheme="dark">{element}</ThemeProvider>);
        });
    };
    /** Finishes the oldest running animation, as if its time had passed */
    const flush = async () => {
        const job = queue.shift() as Job;
        await act(async () => {
            job.done({ finished: true });
        });
    };

    beforeEach(() => {
        jest.useFakeTimers();
        queue = [];
        // jest's native animated mock finishes at once, so record each timing instead and finish it by hand: the sequence and durations are what is tested
        jest.spyOn(Animated, 'timing').mockImplementation(((_value: unknown, config: Job['config']) => ({ start: (done: Job['done']) => queue.push({ config, done }), stop: jest.fn(), reset: jest.fn() })) as never);
    });
    afterEach(() => {
        act(() => renderer?.unmount());
        renderer = undefined;
        introLaunch.seen = false;
        jest.useRealTimers();
        jest.restoreAllMocks();
    });

    it('plays the whole timeline, then glides and ends once the animation is done and the app is ready', async () => {
        const onDone = jest.fn();
        await mount(<Intro mode="full" ready spot={spot} onDone={onDone} />);
        expect(queue).toHaveLength(1);
        expect(queue[0].config.duration).toBeCloseTo(PHASE.hold * 1000);
        await flush();
        expect(onDone).not.toHaveBeenCalled();
        expect(queue[0].config.duration).toBeCloseTo((PHASE.end - PHASE.hold) * 1000);
        expect(queue[0].config.toValue).toBeCloseTo(PHASE.end * 1000);
        await flush();
        expect(onDone).toHaveBeenCalledTimes(1);
    });

    it('waits at the settled mark until the app is ready, and never blocks it', async () => {
        const onDone = jest.fn();
        await mount(<Intro mode="full" ready={false} spot={spot} onDone={onDone} />);
        await flush();
        expect(queue).toHaveLength(0);
        expect(onDone).not.toHaveBeenCalled();
        await act(async () => {
            renderer?.update(
                <ThemeProvider scheme="dark">
                    <Intro mode="full" ready spot={spot} onDone={onDone} />
                </ThemeProvider>,
            );
        });
        expect(queue).toHaveLength(1);
        await flush();
        expect(onDone).toHaveBeenCalledTimes(1);
    });

    it('goes on by itself if the app never reports ready', async () => {
        const onDone = jest.fn();
        await mount(<Intro mode="full" ready={false} spot={spot} onDone={onDone} />);
        await flush();
        await act(async () => {
            jest.advanceTimersByTime(10000);
        });
        expect(queue).toHaveLength(1);
    });

    it('with no header logo (offline screen) the mark fades out instead of gliding', async () => {
        const onDone = jest.fn();
        await mount(<Intro mode="full" ready spot={null} onDone={onDone} />);
        await flush();
        await flush();
        expect(onDone).not.toHaveBeenCalled();
        expect(queue[0].config.toValue).toBe(0);
        await flush();
        expect(onDone).toHaveBeenCalledTimes(1);
    });

    it('a tap ends it with a 200 ms fade', async () => {
        const onDone = jest.fn();
        await mount(<Intro mode="full" ready spot={spot} onDone={onDone} />);
        const tap = renderer?.root.findByProps({ testID: 'intro-tap' });
        await act(async () => {
            tap?.props.onPress();
        });
        expect(queue.at(-1)?.config).toMatchObject({ toValue: 0, duration: SKIP_MS });
        expect(SKIP_MS).toBe(200);
        await flush();
        await flush();
        expect(onDone).toHaveBeenCalledTimes(1);
    });

    it('reduced motion: no car, no smoke, a 300 ms logo fade, then the screen', async () => {
        const onDone = jest.fn();
        await mount(<Intro mode="reduced" ready spot={spot} onDone={onDone} />);
        const text = JSON.stringify(renderer?.toJSON());
        expect(text).not.toContain('porsche');
        expect(text).not.toContain('RNSVG');
        expect(queue).toHaveLength(1);
        expect(queue[0].config).toMatchObject({ toValue: 1, duration: 300 });
        expect(onDone).not.toHaveBeenCalled();
        await flush();
        expect(onDone).toHaveBeenCalledTimes(1);
        expect(queue).toHaveLength(0);
    });

    it('full mode draws the car and the smoke', async () => {
        await mount(<Intro mode="full" ready spot={spot} onDone={jest.fn()} />);
        expect(JSON.stringify(renderer?.toJSON())).toContain('RNSVG');
    });

    it('node budget: one animated view per puff, plus 6 (root, background, stage, car, grey mark, coloured mark), at most 120', async () => {
        await mount(<Intro mode="full" ready spot={spot} onDone={jest.fn()} />);
        const animated = renderer?.root.findAll((n) => n.type === (Animated.View as unknown)).length ?? 0;
        expect(animated).toBe(PUFFS + 6);
        expect(animated).toBeLessThanOrEqual(120);
    });

    it('the gate is OFF unless the build sets EXPO_PUBLIC_INTRO=1: the release shows no intro', async () => {
        jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(false);
        delete process.env.EXPO_PUBLIC_INTRO;
        await mount(<IntroGate ready signedIn offline={false} />);
        expect(renderer?.toJSON()).toBeNull();
        expect(renderer?.root.findAllByProps({ testID: 'intro' })).toHaveLength(0);
        process.env.EXPO_PUBLIC_INTRO = '1';
    });

    it('the gate mounts the overlay on the first start only', async () => {
        jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(false);
        await mount(<IntroGate ready signedIn offline={false} />);
        expect(renderer?.root.findAllByProps({ testID: 'intro' }).length).toBeGreaterThan(0);
        act(() => renderer?.unmount());
        await mount(<IntroGate ready signedIn offline={false} />);
        expect(renderer?.root.findAllByProps({ testID: 'intro' })).toHaveLength(0);
    });

    it('the gate plays the reduced version when the phone removes animations', async () => {
        jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
        await mount(<IntroGate ready signedIn offline={false} />);
        expect(queue[0].config).toMatchObject({ toValue: 1, duration: 300 });
    });
});
