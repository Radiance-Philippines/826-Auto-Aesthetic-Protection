import { Star } from 'lucide-react-native';
import { Pressable, View } from 'react-native';
import { target } from '@/theme';
import { useTheme } from '@/context/theme';

/** A 1 to 5 star picker (or a read-only row when `onChange` is left out) */
export function Stars({ value, onChange, size = 36 }: { value: number; onChange?: (value: number) => void; size?: number }) {
    const c = useTheme();
    return (
        <View accessibilityRole={onChange ? 'radiogroup' : 'image'} accessibilityLabel={`${value} out of 5`} style={{ flexDirection: 'row', gap: 4 }}>
            {[1, 2, 3, 4, 5].map((n) => {
                const star = <Star size={size} color={c.gold} fill={n <= value ? c.gold : 'transparent'} strokeWidth={1.6} />;

                return onChange ? (
                    <Pressable key={n} accessibilityRole="radio" accessibilityLabel={`${n} out of 5`} accessibilityState={{ selected: n === value }} onPress={() => onChange(n)} style={{ minWidth: target, minHeight: target, alignItems: 'center', justifyContent: 'center' }}>
                        {star}
                    </Pressable>
                ) : (
                    <View key={n}>{star}</View>
                );
            })}
        </View>
    );
}
