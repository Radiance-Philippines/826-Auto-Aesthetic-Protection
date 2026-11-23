import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { AccessibilityInfo, Text as RNText } from 'react-native';
import type { ReactElement } from 'react';
import { CountUp, DUR, enterDelay, Enter, PressScale, PRESS_SCALE, setReducedMotion, STAGGER_CAP, useReducedMotion, Skeleton } from '@826/core';
import { StageLine } from '@/ui/StageLine';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let renderer: ReactTestRenderer | undefined;

async function render(element: ReactElement) {
    await act(async () => {
        renderer = create(element);
    });

    return renderer as ReactTestRenderer;
}

const json = (r: ReactTestRenderer) => JSON.stringify(r.toJSON());

afterEach(() => {
    act(() => {
        renderer?.unmount();
        setReducedMotion(false);
    });
    renderer = undefined;
    jest.useRealTimers();
});

describe('motion settings', () => {
    it('stays within 150 to 300 ms, 98% press, and a short stagger of at most six', () => {
        for (const ms of [DUR.release, DUR.enter, DUR.progress, DUR.count]) {
            expect(ms).toBeGreaterThanOrEqual(150);
            expect(ms).toBeLessThanOrEqual(300);
        }

        expect(DUR.press).toBeLessThanOrEqual(150);
        expect(PRESS_SCALE).toBe(0.98);
        expect(enterDelay(0)).toBe(0);
        expect(enterDelay(1)).toBeGreaterThan(0);
        expect(enterDelay(STAGGER_CAP - 1)).toBe(enterDelay(STAGGER_CAP + 20));
        expect(enterDelay(STAGGER_CAP + 20)).toBeLessThanOrEqual(250);
    });

    it('reads the phone setting once and follows changes', () => {
        const isEnabled = jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(true);
        const listen = jest.spyOn(AccessibilityInfo, 'addEventListener');

        jest.isolateModules(() => {
            require('@826/core');
        });

        expect(isEnabled).toHaveBeenCalled();
        expect(listen).toHaveBeenCalledWith('reduceMotionChanged', expect.any(Function));
        jest.restoreAllMocks();
    });

    it('useReducedMotion follows the setting', async () => {
        function Probe() {
            return <RNText>{String(useReducedMotion())}</RNText>;
        }

        const r = await render(<Probe />);
        expect(json(r)).toContain('false');
        await act(async () => setReducedMotion(true));
        expect(json(r)).toContain('true');
    });
});

describe('count-up', () => {
    it('shows the final number at once with reduced motion', async () => {
        await act(async () => setReducedMotion(true));
        expect(json(await render(<CountUp value={300} />))).toContain('300');
    });

    it('starts below the number and ends on it', async () => {
        jest.useFakeTimers();
        const r = await render(<CountUp value={300} />);
        expect(json(r)).toContain('"0"');

        await act(async () => {
            jest.advanceTimersByTime(DUR.count + 100);
        });

        expect(json(r)).toContain('300');
    });
});

describe('entrance, press and loading', () => {
    it('Enter shows its children in place at once with reduced motion', async () => {
        await act(async () => setReducedMotion(true));
        const r = await render(
            <Enter index={3}>
                <RNText>row</RNText>
            </Enter>,
        );
        expect(json(r)).toContain('"opacity":1');
        expect(json(r)).toContain('row');
    });

    it('Enter starts hidden and slightly low when motion is on', async () => {
        const r = await render(
            <Enter>
                <RNText>row</RNText>
            </Enter>,
        );
        expect(json(r)).toContain('"opacity":0');
        expect(json(r)).toContain('row');
    });

    it('PressScale passes the press through and still works with reduced motion', async () => {
        const onPress = jest.fn();
        const onPressIn = jest.fn();

        for (const reduced of [false, true]) {
            await act(async () => setReducedMotion(reduced));
            const r = await render(
                <PressScale accessibilityRole="button" onPress={onPress} onPressIn={onPressIn}>
                    <RNText>card</RNText>
                </PressScale>,
            );
            const host = r.root.findAll((node) => node.props.accessibilityRole === 'button' && typeof node.props.onPressOut === 'function')[0];
            await act(async () => host.props.onPressIn({}));
            await act(async () => host.props.onPressOut({}));
            await act(async () => host.props.onPress({}));
        }

        expect(onPressIn).toHaveBeenCalledTimes(2);
        expect(onPress).toHaveBeenCalledTimes(2);
    });

    it('Skeleton and StageLine render in both settings', async () => {
        for (const reduced of [false, true]) {
            await act(async () => setReducedMotion(reduced));
            expect(json(await render(<Skeleton height={20} />))).toContain('"height":20');
            expect(json(await render(<StageLine stage="quality_check" />))).toContain('Progress: Check, step 3 of 4');
        }
    });
});
