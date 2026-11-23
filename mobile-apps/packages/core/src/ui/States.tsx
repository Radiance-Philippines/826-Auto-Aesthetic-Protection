import { RefreshCw, WifiOff, type LucideIcon } from 'lucide-react-native';
import { View } from 'react-native';
import { problemText, type ApiResult } from '../api/client';
import { useTheme } from '../theme/ThemeProvider';
import { Button } from './Button';
import { Card } from './Card';
import { Text } from './Text';

/** What a failed load shows, with a retry. `offline` has its own wording. */
export function ErrorState({ result, onRetry }: { result: ApiResult<unknown>; onRetry: () => void }) {
    const c = useTheme();
    return (
        <Card accessibilityLiveRegion="polite" style={{ alignItems: 'center', gap: 12, paddingVertical: 28 }}>
            {result.kind === 'offline' ? <WifiOff size={26} color={c.muted} /> : null}
            <Text style={{ textAlign: 'center' }} color={c.muted}>
                {problemText(result)}
            </Text>
            <Button icon={RefreshCw} label="Try again" kind="secondary" onPress={onRetry} />
        </Card>
    );
}

export function EmptyState({ title, body, action }: { title: string; body?: string; action?: { label: string; icon: LucideIcon; onPress: () => void } }) {
    const c = useTheme();
    return (
        <Card style={{ alignItems: 'center', gap: 8, paddingVertical: 28 }}>
            <Text v="title" style={{ textAlign: 'center' }}>
                {title}
            </Text>
            {body ? (
                <Text color={c.muted} style={{ textAlign: 'center' }}>
                    {body}
                </Text>
            ) : null}
            {action ? (
                <View style={{ marginTop: 8 }}>
                    <Button icon={action.icon} label={action.label} onPress={action.onPress} />
                </View>
            ) : null}
        </Card>
    );
}

/** A red line under a field or a form */
export function FormError({ children }: { children?: string | null }) {
    const c = useTheme();
    return children ? (
        <Text accessibilityLiveRegion="polite" accessibilityRole="alert" color={c.danger} style={{ fontSize: 14 }}>
            {children}
        </Text>
    ) : null;
}
