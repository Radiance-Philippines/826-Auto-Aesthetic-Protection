import type { LucideIcon } from 'lucide-react-native';
import { ActivityIndicator, Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { c, radius, target } from '@/theme';
import { Text } from './Text';

type Kind = 'primary' | 'secondary' | 'danger' | 'link';

const KIND: Record<Kind, { bg: string; fg: string; border: string }> = {
    primary: { bg: c.accent, fg: c.onAccent, border: c.accent },
    secondary: { bg: c.surface2, fg: c.text, border: 'rgba(238,240,241,0.18)' },
    danger: { bg: 'rgba(255,158,146,0.12)', fg: c.danger, border: 'rgba(255,158,146,0.18)' },
    link: { bg: 'transparent', fg: c.link, border: 'transparent' },
};

type Props = {
    label: string;
    onPress: () => void;
    kind?: Kind;
    icon?: LucideIcon;
    loading?: boolean;
    disabled?: boolean;
    small?: boolean;
    style?: StyleProp<ViewStyle>;
    accessibilityLabel?: string;
    testID?: string;
};

export function Button({ label, onPress, kind = 'primary', icon: Icon, loading, disabled, small, style, accessibilityLabel, testID }: Props) {
    const k = KIND[kind];
    const off = disabled || loading;

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={accessibilityLabel ?? label}
            accessibilityState={{ disabled: !!off, busy: !!loading }}
            testID={testID}
            disabled={off}
            onPress={onPress}
            style={({ pressed }) => [styles.base, small ? styles.small : null, { backgroundColor: k.bg, borderColor: k.border, opacity: off ? 0.5 : pressed ? 0.85 : 1 }, kind === 'link' ? styles.link : null, style]}
        >
            {loading ? <ActivityIndicator color={k.fg} size="small" /> : Icon ? <Icon size={small ? 16 : 18} color={k.fg} strokeWidth={2.2} /> : null}
            <View style={{ flexShrink: 1 }}>
                <Text weight="semi" color={k.fg} style={{ fontSize: small ? 14 : 16, textAlign: 'center' }}>
                    {label}
                </Text>
            </View>
        </Pressable>
    );
}

const styles = StyleSheet.create({
    base: { minHeight: target, paddingHorizontal: 18, paddingVertical: 10, borderRadius: radius.md, borderWidth: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 },
    small: { paddingHorizontal: 14, borderRadius: radius.sm },
    link: { paddingHorizontal: 6 },
});
