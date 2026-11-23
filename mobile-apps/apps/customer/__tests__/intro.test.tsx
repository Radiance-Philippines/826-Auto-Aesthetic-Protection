import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { AccessibilityInfo, Animated } from 'react-native';
import { carSize, decideIntro, headerLogoSpot, introFrame, introLaunch, logoSize, PHASE, PUFFS, reducedFrame, REDUCED_FADE, sampleIntro, type Layout } from '@/lib/intro';
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
    it('has the phases in order', () => {
        const order = [0, PHASE.cruiseEnd, PHASE.stop, PHASE.smokeStart, PHASE.logoIn, PHASE.logoSolid, PHASE.launch, PHASE.exited, PHASE.hold, PHASE.glideEnd, PHASE.bgFadeStart, PHASE.end];
        expect(PHASE.smokeStart).toBeGreaterThan(PHASE.stop);
        expect(PHASE.logoIn).toBeGreaterThan(PHASE.smokeStart);
        expect(PHASE.launch).toBeGreaterThan(PHASE.wobbleEnd);
        expect(PHASE.hold).toBeGreaterThanOrEqual(PHASE.exited);
        expect(PHASE.bgFadeStart).toBeGreaterThanOrEqual(PHASE.glideEnd);
        expect(PHASE.end).toBeGreaterThan(4.5);
        expect(PHASE.end).toBeLessThanOrEqual(5);
        expect(order.length).toBe(12);
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

    it('3. the burnout holds the car in place with a quick wobble while smoke grows', () => {
        const xs = Array.from({ length: 40 }, (_, i) => at(1.4 + i * 0.01).car.x);
        const spread = Math.max(...xs) - Math.min(...xs);
        expect(spread).toBeGreaterThan(0.5);
        expect(spread).toBeLessThan(5);
        expect(Math.abs(xs[0] - W / 2)).toBeLessThan(4);
        expect(alive(1.2).length).toBeGreaterThan(0);
        expect(puffTotal(2.0)).toBeGreaterThan(puffTotal(1.2));
        const size = (t: number) => Math.max(...at(t).puffs.map((p) => p.size));
        expect(size(2.0)).toBeGreaterThan(size(1.2));
        expect(alive(2.3).length).toBeGreaterThanOrEqual(20);
        expect(PUFFS).toBeGreaterThanOrEqual(30);
        expect(PUFFS).toBeLessThanOrEqual(60);
        // born at the lower, rear (right) of the car
        const first = at(1.12).puffs.find((p) => p.opacity > 0);
        expect(first && first.y).toBeGreaterThan(at(1.12).car.y);
    });

    it('4. the smoke condenses into the mark: the mark rises as the puffs thin out', () => {
        expect(at(PHASE.logoIn - 0.1).logo.opacity).toBe(0);
        expect(at(2.9).logo.opacity).toBeGreaterThan(0.4);
        expect(at(PHASE.logoSolid).logo.opacity).toBe(1);
        expect(at(2.9).logo.opacity).toBeGreaterThan(at(2.6).logo.opacity);
        expect(puffTotal(3.2)).toBeLessThan(puffTotal(2.8));
        expect(puffTotal(PHASE.smokeGone + 0.01)).toBe(0);
        // the cloud has gathered around the mark
        const near = alive(3.0).filter((p) => Math.abs(p.x - W / 2) < W * 0.5 && Math.abs(p.y - H * 0.37) < H * 0.15);
        expect(near.length / alive(3.0).length).toBeGreaterThan(0.7);
        expect(at(3.0).logo.scale).toBeGreaterThan(0.9);
    });

    it('5. the car launches off to the left edge and is gone', () => {
        expect(at(PHASE.launch - 0.05).car.x).toBeGreaterThan(W / 2 - 6);
        expect(at(3.4).car.x).toBeLessThan(at(3.1).car.x);
        expect(at(PHASE.exited - 0.02).car.x - (carSize(W).w / 2) * at(PHASE.exited - 0.02).car.scale).toBeLessThan(0);
        expect(at(PHASE.exited).car.opacity).toBe(0);
        expect(at(PHASE.exited - 0.3).car.opacity).toBe(1);
    });

    it('6. the mark settles, waits centred, then lands exactly on the header logo', () => {
        const hold = at(PHASE.hold);
        expect(hold.logo.x).toBeCloseTo(W / 2);
        expect(hold.logo.y).toBeCloseTo(H * 0.37);
        expect(hold.logo.scale).toBeCloseTo(1);
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
