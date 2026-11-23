import { useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from 'react';
import { AccessibilityInfo, Animated, Easing, Pressable, type PressableProps, type StyleProp, type TextStyle, type ViewStyle } from 'react-native';
import { Text, type AppTextProps } from './Text';

/*
 * Motion, after the Animation blueprint (~/Documents/Projects/blueprints/ANIMATION_BLUEPRINT.md): ease-out,
 * 150 to 300 ms, a short stagger, a small press scale. React Native's own Animated with the native driver where it
 * can (opacity and transform); nothing here blocks a tap. The phone's "remove animations" setting turns all of it off.
 */
export const DUR = { press: 120, release: 160, enter: 240, progress: 300, count: 300 } as const;
const EASE_OUT = Easing.bezier(0.2, 0.7, 0.2, 1);
export const PRESS_SCALE = 0.98;
/** Row i waits i x 40 ms, and no row waits longer than the sixth */
export const STAGGER = 40;
export const STAGGER_CAP = 6;
export const enterDelay = (index: number) => Math.min(index, STAGGER_CAP - 1) * STAGGER;

// The setting is read once at start (the splash screen is still up) and followed after that
let reduced = false;
const listeners = new Set<() => void>();
/** Also how tests flip the setting */
export const setReducedMotion = (value: boolean) => {
    if (value !== reduced) {
        reduced = value;
        listeners.forEach((listener) => listener());
    }
};
void AccessibilityInfo.isReduceMotionEnabled?.()?.then(setReducedMotion).catch(() => {});
AccessibilityInfo.addEventListener?.('reduceMotionChanged', setReducedMotion);

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
};

/** True when the phone is set to reduce or remove animations: show the end state, move nothing */
export const useReducedMotion = (): boolean => useSyncExternalStore(subscribe, () => reduced, () => reduced);

/** A Pressable that dips to 98% while pressed (and dims a little). Reduced motion: no scale, only the dim. */
export function PressScale({ style, onPressIn, onPressOut, disabled, ...rest }: Omit<PressableProps, 'style'> & { style?: StyleProp<ViewStyle> }) {
    const motion = useReducedMotion();
    const pressed = useRef(new Animated.Value(0)).current;
    const to = (value: number, duration: number) => {
        if (motion) {
            pressed.setValue(value);
        } else {
            Animated.timing(pressed, { toValue: value, duration, easing: EASE_OUT, useNativeDriver: true }).start();
        }
    };
    const dim = pressed.interpolate({ inputRange: [0, 1], outputRange: [1, 0.85] });
    const scale = pressed.interpolate({ inputRange: [0, 1], outputRange: [1, motion ? 1 : PRESS_SCALE] });

    return (
        <AnimatedPressable
            {...rest}
            disabled={disabled}
            onPressIn={(event) => {
                to(1, DUR.press);
                onPressIn?.(event);
            }}
            onPressOut={(event) => {
                to(0, DUR.release);
                onPressOut?.(event);
            }}
            style={[style, { transform: [{ scale }] }, disabled ? null : { opacity: dim }]}
        />
    );
}
const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

/** A list row or card that rises into place when it first shows. `index` staggers the first six. */
export function Enter({ index = 0, children, style }: { index?: number; children: ReactNode; style?: StyleProp<ViewStyle> }) {
    const motion = useReducedMotion();
    const shown = useRef(new Animated.Value(motion ? 1 : 0)).current;

    useEffect(() => {
        if (motion) {
            shown.setValue(1);

            return;
        }

        const run = Animated.timing(shown, { toValue: 1, duration: DUR.enter, delay: enterDelay(index), easing: EASE_OUT, useNativeDriver: true });
        run.start();

        return () => run.stop();
        // plays once, when the row mounts
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [motion]);

    return <Animated.View style={[style, { opacity: shown, transform: [{ translateY: shown.interpolate({ inputRange: [0, 1], outputRange: [8, 0] }) }] }]}>{children}</Animated.View>;
}

/** A whole number that counts up to its value (and from its old value when it changes). Reduced motion: the number. */
export function CountUp({ value, style, ...rest }: Omit<AppTextProps, 'children'> & { value: number; style?: StyleProp<TextStyle> }) {
    const motion = useReducedMotion();
    const [shown, setShown] = useState(motion ? value : 0);
    const from = useRef(motion ? value : 0);

    useEffect(() => {
        if (motion) {
            from.current = value;
            setShown(value);

            return;
        }

        const progress = new Animated.Value(0);
        const start = from.current;
        const id = progress.addListener(({ value: p }) => {
            from.current = Math.round(start + (value - start) * p);
            setShown(from.current);
        });
        const run = Animated.timing(progress, { toValue: 1, duration: DUR.count, easing: EASE_OUT, useNativeDriver: false });
        run.start(() => {
            from.current = value;
            setShown(value);
        });

        return () => {
            run.stop();
            progress.removeListener(id);
        };
    }, [value, motion]);

    return (
        <Text {...rest} style={style}>
            {shown}
        </Text>
    );
}
