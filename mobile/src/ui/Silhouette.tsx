import { View } from 'react-native';
import Svg, { Circle, Ellipse, G, Path } from 'react-native-svg';
import { radius } from '@/theme';
import { useTheme } from '@/context/theme';

// Side profiles from resources/js/components/VehicleSilhouette.tsx, one colour with the glass and wheel faces let through.
type Kind = 'motorcycle' | 'small' | 'sedan' | 'suv' | 'pickup';
type Shape = { body: string; glass: string[]; wheels: { cx: number; cy: number; r: number }[] };

const TYPE_KIND: Record<string, Kind> = { motorcycle: 'motorcycle', hatchback: 'small', sedan: 'sedan', coupe: 'sedan', suv: 'suv', van: 'suv', pickup: 'pickup' };

const SHAPES: Record<Exclude<Kind, 'motorcycle'>, Shape> = {
    small: {
        body: 'M36,74 L33,61 C33,53 36,48 42,45 C52,39 66,33.5 82,32 C100,30.5 118,30.6 131,32.2 C142,36 151,42.6 160,48 L195,53 C202,54.4 206,58 207,63 L207,72 C207,73.4 206,74 204,74 L188.5,74 A17,17 0 1 0 155.5,74 L84.5,74 A17,17 0 1 0 51.5,74 Z',
        glass: ['M45,48.4 C53,42.6 65,37.4 80,35.8 L99,34.6 L99,48.6 Z', 'M103,34.5 L129.5,35.2 C138,38.4 146,43.4 152.5,48 L103,48.6 Z'],
        wheels: [{ cx: 68, cy: 70, r: 14 }, { cx: 172, cy: 70, r: 14 }],
    },
    sedan: {
        body: 'M18,74 L16,63 C16,57 19,55 25,54.5 L58,51 C66,44 76,37.5 90,34.4 C108,31 128,31 140,32.6 C150,36 160,43 170,48 L210,53 C218,54.5 224,58 226,63 L226,72 C226,73.4 225,74 223,74 L200.5,74 A17,17 0 1 0 167.5,74 L78.5,74 A17,17 0 1 0 45.5,74 Z',
        glass: ['M66,50.2 C73,44.4 81,40.2 92,37.8 L111,36.4 L111,49.4 Z', 'M115,36.3 L137.5,35.9 C146,39.4 154,44 161,48.4 L115,49.3 Z'],
        wheels: [{ cx: 62, cy: 70, r: 14 }, { cx: 184, cy: 70, r: 14 }],
    },
    suv: {
        body: 'M16,72 L15,52 C15,44 18,40 24,38 L38,26 C42,22 46,21 52,21 L140,21 C146,21 150,23 154,27 L172,42 L212,47 C220,48.5 225,53 226,59 L226,70 C226,71.4 225,72 223,72 L202.6,72 A19,19 0 1 0 165.4,72 L80.6,72 A19,19 0 1 0 43.4,72 Z',
        glass: ['M30,40 L42,28.4 C45,25.8 48,25 52,25 L91,25 L91,40.6 Z', 'M95,25 L139,25 C143,25 146,26.4 149,29.4 L163,41.6 L95,41.4 Z'],
        wheels: [{ cx: 62, cy: 68, r: 16 }, { cx: 184, cy: 68, r: 16 }],
    },
    pickup: {
        body: 'M14,72 L13,46 L16,43.6 L97,43.6 L100,26 C101,22 104,20 108,20 L150,20 C155,20 158,22 161,25 L176,41 L214,46 C221,47.5 226,52 227,58 L227,70 C227,71.4 226,72 224,72 L202.6,72 A19,19 0 1 0 165.4,72 L76.6,72 A19,19 0 1 0 39.4,72 Z',
        glass: ['M104.4,40 L106.2,26 C106.6,24.6 107.6,23.8 109.2,23.8 L128,23.8 L128,40 Z', 'M132,23.8 L149,23.8 C152.5,23.8 155,24.8 157,26.8 L170.6,40 L132,40 Z'],
        wheels: [{ cx: 58, cy: 68, r: 16 }, { cx: 184, cy: 68, r: 16 }],
    },
};

/** A unit of a given type drawn in a tile. It is a drawing of the type, never the customer's own car. */
export function Silhouette({ type, width = 96, height = 56 }: { type: string | null | undefined; width?: number; height?: number }) {
    const c = useTheme();
    const { body, cut } = c.car;
    const kind = (type && TYPE_KIND[type]) || 'sedan';

    return (
        <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={{ width, height, borderRadius: radius.md, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }}>
            <Svg width={width - 6} height={(width - 6) * (90 / 240)} viewBox="0 0 240 90">
                <Ellipse cx="120" cy="85.5" rx="104" ry="2.6" fill={c.car.shadow} />
                {kind === 'motorcycle' ? (
                    <G>
                        <Path d="M76,60 L101,57 C104,50 111,45.5 120,44.5 L139,44 C145,44 149,46.4 151,50 L157,55.6 L163,50.5 L170,50.5 L167,58 L150,61.5 L113,64.5 Z" fill={body} />
                        <Path d="M160,52 L171,70" stroke={body} strokeWidth={4} strokeLinecap="round" fill="none" />
                        <Path d="M86,66.5 L120,66" stroke={body} strokeWidth={4} strokeLinecap="round" fill="none" />
                        {[72, 170].map((cx) => (
                            <Circle key={cx} cx={cx} cy={70} r={14} stroke={body} strokeWidth={5} fill="none" />
                        ))}
                    </G>
                ) : (
                    <G>
                        <Path d={SHAPES[kind].body} fill={body} />
                        {SHAPES[kind].glass.map((d) => (
                            <Path key={d} d={d} fill={cut} />
                        ))}
                        {SHAPES[kind].wheels.map((w) => (
                            <G key={w.cx}>
                                <Circle cx={w.cx} cy={w.cy} r={w.r} fill={body} />
                                <Circle cx={w.cx} cy={w.cy} r={w.r * 0.62} fill={cut} />
                                <Circle cx={w.cx} cy={w.cy} r={w.r * 0.2} fill={body} />
                            </G>
                        ))}
                    </G>
                )}
            </Svg>
        </View>
    );
}
