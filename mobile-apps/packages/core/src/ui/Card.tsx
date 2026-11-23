import { View, type ViewProps } from 'react-native';
import { radius } from '../theme/theme';
import { useTheme } from '../theme/ThemeProvider';

/** A graphite panel with a hairline edge */
export function Card({ style, ...rest }: ViewProps) {
    const c = useTheme();
    return <View {...rest} style={[{ backgroundColor: c.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: c.line, padding: 16 }, style]} />;
}
