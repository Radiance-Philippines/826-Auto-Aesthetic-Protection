import { Check } from 'lucide-react-native';
import { View } from 'react-native';
import { Button } from './Button';
import { Sheet } from './Sheet';
import { FormError } from './States';
import { Text } from './Text';
import { c } from '@/theme';

/** "Are you sure" for what can't be taken back (Exited) */
export function Confirm({ open, title, body, action, busy, problem, onConfirm, onClose }: { open: boolean; title: string; body: string; action: string; busy?: boolean; problem?: string | null; onConfirm: () => void; onClose: () => void }) {
    return (
        <Sheet open={open} onClose={onClose} title={title}>
            <Text color={c.muted}>{body}</Text>
            <FormError>{problem}</FormError>
            <View style={{ gap: 10 }}>
                <Button label={action} icon={Check} loading={busy} onPress={onConfirm} />
                <Button label="Cancel" kind="secondary" onPress={onClose} />
            </View>
        </Sheet>
    );
}
