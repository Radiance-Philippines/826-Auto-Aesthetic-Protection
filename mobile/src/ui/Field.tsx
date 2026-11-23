import type { LucideIcon } from 'lucide-react-native';
import { TextInput, View, type TextInputProps } from 'react-native';
import { c, fonts, radius, target } from '@/theme';
import { Text } from './Text';

type Props = Omit<TextInputProps, 'style'> & {
    label: string;
    error?: string | null;
    hint?: string;
    icon?: LucideIcon;
    /** "optional" next to the label */
    optional?: boolean;
};

/** A labelled text field with its error beneath it. Fields stay 48dp tall. */
export function Field({ label, error, hint, icon: Icon, optional, multiline, ...rest }: Props) {
    return (
        <View style={{ gap: 6 }}>
            <Text weight="semi" style={{ fontSize: 14 }} color={c.muted}>
                {label}
                {optional ? ' (optional)' : ''}
            </Text>
            <View style={{ flexDirection: 'row', alignItems: multiline ? 'flex-start' : 'center', gap: 10, minHeight: target + 4, borderRadius: radius.md, borderWidth: 1, borderColor: error ? c.danger : c.field, backgroundColor: c.surface, paddingHorizontal: 14 }}>
                {Icon ? <Icon size={18} color={c.muted} style={multiline ? { marginTop: 14 } : undefined} /> : null}
                <TextInput
                    accessibilityLabel={label}
                    placeholderTextColor={c.field}
                    selectionColor={c.accent}
                    multiline={multiline}
                    textAlignVertical={multiline ? 'top' : 'center'}
                    {...rest}
                    style={{ flex: 1, color: c.text, fontFamily: fonts.regular, fontSize: 16, paddingVertical: 12, minHeight: multiline ? 96 : target }}
                />
            </View>
            {error ? (
                <Text accessibilityLiveRegion="polite" color={c.danger} style={{ fontSize: 13 }}>
                    {error}
                </Text>
            ) : hint ? (
                <Text v="small">{hint}</Text>
            ) : null}
        </View>
    );
}
