import type { LucideIcon } from 'lucide-react-native';
import type { ComponentRef, ReactNode, Ref } from 'react';
import { TextInput, View, type TextInputProps } from 'react-native';
import { fonts, radius, target } from '../theme/theme';
import { useTheme } from '../theme/ThemeProvider';
import { Text } from './Text';

/** What a ref to a field's text input holds (focus(), blur()) */
export type InputRef = ComponentRef<typeof TextInput>;

type Props = Omit<TextInputProps, 'style'> & {
    label: string;
    error?: string | null;
    hint?: string;
    icon?: LucideIcon;
    /** "optional" next to the label */
    optional?: boolean;
    /** Something pressable at the end of the field (show/hide password) */
    right?: ReactNode;
    /** To move the cursor on from the keyboard's return key */
    inputRef?: Ref<InputRef>;
};

/** A labelled text field with its error beneath it. Fields stay 48dp tall. */
export function Field({ label, error, hint, icon: Icon, optional, right, inputRef, multiline, ...rest }: Props) {
    const c = useTheme();
    return (
        <View style={{ gap: 6 }}>
            <Text weight="semi" style={{ fontSize: 14 }} color={c.muted}>
                {label}
                {optional ? ' (optional)' : ''}
            </Text>
            <View style={{ flexDirection: 'row', alignItems: multiline ? 'flex-start' : 'center', gap: 10, minHeight: target + 4, borderRadius: radius.md, borderWidth: 1, borderColor: error ? c.danger : c.field, backgroundColor: c.surface, paddingHorizontal: 14 }}>
                {Icon ? <Icon size={18} color={c.muted} style={multiline ? { marginTop: 14 } : undefined} /> : null}
                <TextInput
                    ref={inputRef}
                    accessibilityLabel={label}
                    placeholderTextColor={c.field}
                    selectionColor={c.accent}
                    keyboardAppearance={c.scheme}
                    multiline={multiline}
                    textAlignVertical={multiline ? 'top' : 'center'}
                    {...rest}
                    style={{ flex: 1, color: c.text, fontFamily: fonts.regular, fontSize: 16, paddingVertical: 12, minHeight: multiline ? 96 : target }}
                />
                {right}
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
