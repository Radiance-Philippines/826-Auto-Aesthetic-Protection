import { X } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { radius, target } from '../theme/theme';
import { useTheme } from '../theme/ThemeProvider';
import { useReducedMotion } from './motion';
import { Text } from './Text';

/** A bottom sheet (the app's own, instead of a native picker). The close button is the way out: there is no Back-key handling to lean on. */
export function Sheet({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
    const c = useTheme();
    const motion = useReducedMotion();
    const insets = useSafeAreaInsets();

    return (
        <Modal visible={open} transparent animationType={motion ? 'none' : 'slide'} onRequestClose={onClose} statusBarTranslucent navigationBarTranslucent>
            <KeyboardAvoidingView style={{ flex: 1, justifyContent: 'flex-end' }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
                <Pressable accessibilityLabel="Close" accessibilityRole="button" onPress={onClose} style={{ position: 'absolute', top: 0, bottom: 0, left: 0, right: 0, backgroundColor: c.scrim }} />
                <View accessibilityViewIsModal style={{ maxHeight: '85%', backgroundColor: c.surface, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, borderWidth: 1, borderColor: c.line, paddingBottom: insets.bottom + 12 }}>
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingLeft: 20, paddingRight: 8, paddingTop: 8 }}>
                        <Text v="title" accessibilityRole="header">
                            {title}
                        </Text>
                        <Pressable accessibilityRole="button" accessibilityLabel="Close" onPress={onClose} hitSlop={4} style={{ width: target, height: target, alignItems: 'center', justifyContent: 'center' }}>
                            <X size={22} color={c.muted} />
                        </Pressable>
                    </View>
                    <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 8, gap: 12 }}>
                        {children}
                    </ScrollView>
                </View>
            </KeyboardAvoidingView>
        </Modal>
    );
}
