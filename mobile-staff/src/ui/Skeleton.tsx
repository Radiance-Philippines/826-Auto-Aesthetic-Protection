import { useEffect, useRef } from 'react';
import { Animated, type DimensionValue, type ViewStyle } from 'react-native';
import { c, radius } from '@/theme';

/** A grey block that breathes while a screen loads */
export function Skeleton({ height = 16, width = '100%', style }: { height?: number; width?: DimensionValue; style?: ViewStyle }) {
    const pulse = useRef(new Animated.Value(0.45)).current;

    useEffect(() => {
        const loop = Animated.loop(Animated.sequence([Animated.timing(pulse, { toValue: 1, duration: 700, useNativeDriver: true }), Animated.timing(pulse, { toValue: 0.45, duration: 700, useNativeDriver: true })]));
        loop.start();

        return () => loop.stop();
    }, [pulse]);

    return <Animated.View accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={[{ height, width, borderRadius: radius.sm, backgroundColor: c.surface2, opacity: pulse }, style]} />;
}
