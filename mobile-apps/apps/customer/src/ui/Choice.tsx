import { Check } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { View } from 'react-native';
import { radius, target, useTheme, PressScale, Text } from '@826/core';

type Props = {
    selected: boolean;
    onPress: () => void;
    title: string;
    sub?: string | null;
    /** A round radio (one pick) or a square box (many) */
    kind?: 'radio' | 'box';
    disabled?: boolean;
    left?: ReactNode;
    right?: ReactNode;
    testID?: string;
    /** Long running text (a consent line) in the regular weight */
    plain?: boolean;
};

/** A tappable card with a radio or a box: the design's unit and service pickers */
export function Choice({ selected, onPress, title, sub, kind = 'radio', disabled, left, right, testID, plain }: Props) {
    const c = useTheme();
    return (
        <PressScale
            testID={testID}
            accessibilityRole={kind === 'radio' ? 'radio' : 'checkbox'}
            accessibilityLabel={sub ? `${title}, ${sub}` : title}
            accessibilityState={{ selected, checked: selected, disabled }}
            disabled={disabled}
            onPress={onPress}
            style={{ minHeight: target + 8, flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12, borderRadius: radius.md, borderWidth: 1, borderColor: selected ? c.accent : c.line, backgroundColor: selected ? c.accentSoft : c.surface, opacity: disabled ? 0.5 : undefined }}
        >
            <View style={{ width: 22, height: 22, borderRadius: kind === 'radio' ? 11 : 6, borderWidth: 2, borderColor: selected ? c.accent : c.field, backgroundColor: selected && kind === 'box' ? c.accent : 'transparent', alignItems: 'center', justifyContent: 'center' }}>
                {selected ? kind === 'box' ? <Check size={14} color={c.onAccent} strokeWidth={3.2} /> : <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: c.accent }} /> : null}
            </View>
            {left}
            <View style={{ flex: 1, gap: 2 }}>
                <Text weight={plain ? 'regular' : 'bold'} style={{ fontSize: 15 }}>
                    {title}
                </Text>
                {sub ? <Text v="small">{sub}</Text> : null}
            </View>
            {right}
        </PressScale>
    );
}
