import { Pressable, ScrollView, View } from 'react-native';
import { c, fonts, radius, target } from '@/theme';
import { Text } from './Text';

export type Pill<K extends string | number | null> = { key: K; label: string; count?: number };

/** A row of filter pills (All 8, Arrived 2, ...). Scrolls sideways; each is a 48dp-high target. */
export function Pills<K extends string | number | null>({ items, value, onChange, label }: { items: Pill<K>[]; value: K; onChange: (key: K) => void; label: string }) {
    return (
        <ScrollView accessibilityLabel={label} horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingRight: 8 }} style={{ flexGrow: 0 }}>
            {items.map((item) => {
                const on = item.key === value;

                return (
                    <Pressable
                        key={String(item.key)}
                        accessibilityRole="button"
                        accessibilityLabel={item.count === undefined ? item.label : `${item.label}, ${item.count}`}
                        accessibilityState={{ selected: on }}
                        onPress={() => onChange(item.key)}
                        style={{ minHeight: target - 8, paddingHorizontal: 14, borderRadius: radius.pill, flexDirection: 'row', alignItems: 'center', gap: 6, justifyContent: 'center', backgroundColor: on ? c.accent : c.surface2 }}
                    >
                        <Text style={{ fontFamily: fonts.semi, fontSize: 14 }} color={on ? c.onAccent : c.muted}>
                            {item.label}
                            {item.count === undefined ? '' : ` ${item.count}`}
                        </Text>
                    </Pressable>
                );
            })}
        </ScrollView>
    );
}

/** A tap-sized circle with a person's initials */
export function Avatar({ text, size = 44 }: { text: string; size?: number }) {
    return (
        <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants" style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: c.tone.teal.bg, alignItems: 'center', justifyContent: 'center' }}>
            <Text weight="bold" color={c.accent} style={{ fontSize: size * 0.34 }}>
                {text}
            </Text>
        </View>
    );
}
