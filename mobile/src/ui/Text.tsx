import { Text as RNText, type TextProps, type TextStyle } from 'react-native';
import { useTheme } from '@/context/theme';
import { fonts, type Palette } from '@/theme';

type Variant = 'display' | 'title' | 'body' | 'label' | 'number' | 'small';

const styles = (c: Palette): Record<Variant, TextStyle> => ({
    // The heading face: all caps, italic, set wide
    display: { fontFamily: fonts.display, fontSize: 28, lineHeight: 34, letterSpacing: 1.2, textTransform: 'uppercase', color: c.text },
    title: { fontFamily: fonts.bold, fontSize: 17, lineHeight: 23, color: c.text },
    body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: c.text },
    small: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: c.muted },
    label: { fontFamily: fonts.semi, fontSize: 12, lineHeight: 16, letterSpacing: 1, textTransform: 'uppercase', color: c.muted },
    number: { fontFamily: fonts.bold, fontSize: 22, lineHeight: 28, color: c.text },
});

export type AppTextProps = TextProps & { v?: Variant; color?: string; weight?: 'regular' | 'medium' | 'semi' | 'bold' };

/** Dynamic type is respected; very large settings stop at 1.5x so the layout holds */
export function Text({ v = 'body', color, weight, style, ...rest }: AppTextProps) {
    const c = useTheme();

    return <RNText maxFontSizeMultiplier={1.5} {...rest} style={[styles(c)[v], color ? { color } : null, weight ? { fontFamily: fonts[weight] } : null, style]} />;
}
