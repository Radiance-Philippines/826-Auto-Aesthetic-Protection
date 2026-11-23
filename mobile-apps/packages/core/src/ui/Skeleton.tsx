import { useEffect, useRef, useState } from 'react';
import { Animated, Easing, View, type DimensionValue, type ViewStyle } from 'react-native';
import { useTheme } from '../theme/ThemeProvider';
import { radius } from '../theme/theme';
import { useReducedMotion } from './motion';

/** A grey block with a soft band of light sweeping over it while a screen loads. Reduced motion: the still block. */
export function Skeleton({ height = 16, width = '100%', style }: { height?: number; width?: DimensionValue; style?: ViewStyle }) {
    const c = useTheme();
    const motion = useReducedMotion();
    const sweep = useRef(new Animated.Value(0)).current;
    const [span, setSpan] = useState(0);

    useEffect(() => {
        if (motion || !span) {
            sweep.setValue(0);

            return;
        }

        const loop = Animated.loop(Animated.timing(sweep, { toValue: 1, duration: 1200, easing: Easing.inOut(Easing.quad), useNativeDriver: true }));
        loop.start();

        return () => loop.stop();
    }, [motion, span, sweep]);

    return (
        <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants" onLayout={(event) => setSpan(event.nativeEvent.layout.width)} style={[{ height, width, borderRadius: radius.sm, backgroundColor: c.surface2, overflow: 'hidden' }, style]}>
            {motion || !span ? null : (
                <Animated.View style={{ position: 'absolute', top: 0, bottom: 0, width: span * 0.4, backgroundColor: c.shimmer, transform: [{ translateX: sweep.interpolate({ inputRange: [0, 1], outputRange: [-span * 0.4, span] }) }] }} />
            )}
        </View>
    );
}
