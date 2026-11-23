import { useRouter } from 'expo-router';
import { ChevronLeft } from 'lucide-react-native';
import { useState, type ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, View, type StyleProp, type ViewStyle } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { c, target } from '@/theme';
import { Brand } from './Brand';
import { Text } from './Text';

type Props = {
    children: ReactNode;
    onRefresh?: () => Promise<unknown> | void;
    /** The small 826 mark above the title (tab roots) */
    brand?: boolean;
    /** Above the title instead of the mark: the role and branch chip */
    top?: ReactNode;
    /** One line under the title */
    subtitle?: string;
    title?: string;
    /** "Home", "Garage": a back link above the title */
    back?: string;
    right?: ReactNode;
    /** Leave room under the content for the tab bar */
    tabs?: boolean;
    contentStyle?: StyleProp<ViewStyle>;
    /** A footer pinned above the keyboard / home indicator (wizard buttons) */
    footer?: ReactNode;
};

/**
 * Every screen: safe-area top (notch, status bar), a title in the heading
 * face, pull to refresh, and keyboard handling for iOS (padding) and Android
 * (the system resizes the window).
 */
export function Screen({ children, onRefresh, brand, top, subtitle, title, back, right, tabs, contentStyle, footer }: Props) {
    const insets = useSafeAreaInsets();
    const router = useRouter();
    const [busy, setBusy] = useState(false);

    return (
        <KeyboardAvoidingView style={{ flex: 1, backgroundColor: c.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
            <ScrollView
                keyboardShouldPersistTaps="handled"
                keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
                automaticallyAdjustKeyboardInsets
                contentContainerStyle={[{ paddingTop: insets.top + 12, paddingHorizontal: 20, paddingBottom: (tabs ? 24 : insets.bottom + 24) + (footer ? 8 : 0), gap: 16 }, contentStyle]}
                refreshControl={onRefresh ? <RefreshControl refreshing={busy} tintColor={c.accent} colors={[c.accent]} progressBackgroundColor={c.surface2} progressViewOffset={insets.top} onRefresh={async () => { setBusy(true); try { await onRefresh(); } finally { setBusy(false); } }} /> : undefined}
            >
                {back ? (
                    <Pressable accessibilityRole="button" accessibilityLabel={`Back to ${back}`} hitSlop={8} onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))} style={{ flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: target - 12, alignSelf: 'flex-start' }}>
                        <ChevronLeft size={22} color={c.link} strokeWidth={2.4} />
                        <Text color={c.link} weight="semi" style={{ fontSize: 16 }}>
                            {back}
                        </Text>
                    </Pressable>
                ) : top ? (
                    top
                ) : brand ? (
                    <Brand />
                ) : null}
                {title ? (
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                        <Text v="display" accessibilityRole="header" style={{ flex: 1 }}>
                            {title}
                        </Text>
                        {right}
                    </View>
                ) : null}
                {subtitle ? <Text color={c.muted} style={{ marginTop: -8 }}>{subtitle}</Text> : null}
                {children}
            </ScrollView>
            {/* Content scrolls under the status bar: keep the clock readable */}
            <View pointerEvents="none" style={{ position: 'absolute', top: 0, left: 0, right: 0, height: insets.top, backgroundColor: c.bg }} />
            {footer ? <View style={{ paddingHorizontal: 20, paddingTop: 10, paddingBottom: (tabs ? 0 : insets.bottom) + 12, borderTopWidth: 1, borderTopColor: c.line, backgroundColor: c.bg }}>{footer}</View> : null}
        </KeyboardAvoidingView>
    );
}
