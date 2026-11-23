import type { LucideIcon } from 'lucide-react-native';
import { ActivityIndicator, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { useTheme } from '@/context/theme';
import { radius, target, type Palette } from '@/theme';
import { PressScale } from './motion';
import { Text } from './Text';

type Kind = 'primary' | 'secondary' | 'danger' | 'link';

const kinds = (c: Palette): Record<Kind, { bg: string; fg: string; border: string }> => ({
    primary: { bg: c.accent, fg: c.onAccent, border: c.accent },
    secondary: { bg: c.surface2, fg: c.text, border: c.outline },
    danger: { bg: c.dangerSoft, fg: c.danger, border: c.dangerLine },
    link: { bg: 'transparent', fg: c.link, border: 'transparent' },
});

type Props = {
    label: string;
    onPress: () => void;
    /** Every button leads with an icon (house rule), so this is not optional */
    icon: LucideIcon;
    kind?: Kind;
    loading?: boolean;
    disabled?: boolean;
    small?: boolean;
    style?: StyleProp<ViewStyle>;
    accessibilityLabel?: string;
    testID?: string;
};

export function Button({ label, onPress, icon: Icon, kind = 'primary', loading, disabled, small, style, accessibilityLabel, testID }: Props) {
    const k = kinds(useTheme())[kind];
    const off = disabled || loading;

    return (
        <PressScale
            accessibilityRole="button"
            accessibilityLabel={accessibilityLabel ?? label}
            accessibilityState={{ disabled: !!off, busy: !!loading }}
            testID={testID}
            disabled={off}
            onPress={onPress}
            style={[styles.base, small ? styles.small : null, { backgroundColor: k.bg, borderColor: k.border }, off ? { opacity: 0.5 } : null, kind === 'link' ? styles.link : null, style]}
        >
            {loading ? <ActivityIndicator color={k.fg} size="small" /> : <Icon size={small ? 16 : 18} color={k.fg} strokeWidth={2.2} />}
            <View style={{ flexShrink: 1 }}>
                <Text weight="semi" color={k.fg} style={{ fontSize: small ? 14 : 16, textAlign: 'center' }}>
                    {label}
                </Text>
            </View>
        </PressScale>
    );
}

const styles = StyleSheet.create({
    base: { minHeight: target, paddingHorizontal: 18, paddingVertical: 10, borderRadius: radius.md, borderWidth: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 },
    small: { paddingHorizontal: 14, borderRadius: radius.sm },
    link: { paddingHorizontal: 6 },
});
