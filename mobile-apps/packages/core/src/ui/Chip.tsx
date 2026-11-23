import { View } from 'react-native';
import { radius, type Tone } from '../theme/theme';
import { useTheme } from '../theme/ThemeProvider';
import { Text } from './Text';

/** A status chip: a dot and a word on a faint tint (design system DS3) */
export function Chip({ label, tone = 'neutral', dot = true }: { label: string; tone?: Tone; dot?: boolean }) {
    const c = useTheme();
    const t = c.tone[tone];

    return (
        <View accessible accessibilityLabel={label} style={{ flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: t.bg, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 5, alignSelf: 'flex-start' }}>
            {dot ? <View style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: t.fg }} /> : null}
            <Text weight="semi" color={t.fg} style={{ fontSize: 12, lineHeight: 16 }}>
                {label}
            </Text>
        </View>
    );
}
