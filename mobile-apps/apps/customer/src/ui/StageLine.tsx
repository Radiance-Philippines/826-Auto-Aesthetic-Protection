import { useEffect, useRef } from 'react';
import { Animated, Easing, View } from 'react-native';
import type { Stage } from '@/api';
import { useTheme, DUR, useReducedMotion, Text } from '@826/core';
import { STEP_LABELS, stepIndex } from '@/lib/stage';

/**
 * The four dots on the home card: Arrived, Working, Check, Ready. The current one is bold, earlier ones are filled.
 * The fill runs along the line to the current step when the card shows (and on to the next step when it moves).
 * Reduced motion: it is already filled.
 */
export function StageLine({ stage }: { stage: Stage }) {
    const c = useTheme();
    const motion = useReducedMotion();
    const at = Math.max(stepIndex(stage), 0);
    // 0..3: how far along the line the fill has got
    const progress = useRef(new Animated.Value(motion ? at : 0)).current;

    useEffect(() => {
        if (motion) {
            progress.setValue(at);

            return;
        }

        const run = Animated.timing(progress, { toValue: at, duration: DUR.progress, easing: Easing.bezier(0.2, 0.7, 0.2, 1), useNativeDriver: true });
        run.start();

        return () => run.stop();
    }, [at, motion, progress]);

    /** A half-line of a step's row: a track with the accent fill growing from its start. It fills while progress runs over [from, to]. */
    const half = (index: number, side: 'left' | 'right') => {
        const from = side === 'left' ? index - 0.5 : index;
        const to = side === 'left' ? index : index + 0.5;
        const hidden = side === 'left' ? index === 0 : index === 3;

        return (
            <View style={{ flex: 1, height: 2, backgroundColor: hidden ? 'transparent' : c.surface2, overflow: 'hidden' }}>
                {hidden ? null : <Animated.View style={{ flex: 1, backgroundColor: c.accent, transformOrigin: ['0%', '50%', 0], transform: [{ scaleX: progress.interpolate({ inputRange: [from, to], outputRange: [0, 1], extrapolate: 'clamp' }) }] }} />}
            </View>
        );
    };

    return (
        <View accessible accessibilityLabel={`Progress: ${STEP_LABELS[at]}, step ${at + 1} of 4`} style={{ flexDirection: 'row' }}>
            {STEP_LABELS.map((label, index) => (
                <View key={label} style={{ flex: 1, alignItems: index === 0 ? 'flex-start' : index === 3 ? 'flex-end' : 'center' }}>
                    <View style={{ flexDirection: 'row', alignItems: 'center', width: '100%' }}>
                        {half(index, 'left')}
                        <View style={{ width: 14, height: 14, borderRadius: 7, backgroundColor: c.surface2, overflow: 'hidden' }}>
                            <Animated.View style={{ flex: 1, backgroundColor: c.accent, opacity: progress.interpolate({ inputRange: [index - 0.3, index], outputRange: [0, 1], extrapolate: 'clamp' }) }} />
                        </View>
                        {half(index, 'right')}
                    </View>
                    <Text style={{ fontSize: 13, marginTop: 6 }} color={index === at ? c.text : c.muted} weight={index === at ? 'bold' : 'regular'}>
                        {label}
                    </Text>
                </View>
            ))}
        </View>
    );
}
