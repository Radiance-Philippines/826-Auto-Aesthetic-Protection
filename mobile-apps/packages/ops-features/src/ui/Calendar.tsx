import { ChevronLeft, ChevronRight } from 'lucide-react-native';
import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { addMonths, daysInMonth, manilaToday, monthStart, monthTitle, pad, parseDate, c, radius, target, Text } from '@826/core';

const WEEK = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const WEEK_FULL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** A month grid (the app's own, no native date picker). Days before `min` are off. */
export function Calendar({ value, onPick, min = manilaToday() }: { value: string | null; onPick: (date: string) => void; min?: string }) {
    const [month, setMonth] = useState(() => (value ?? min).slice(0, 7));
    const start = monthStart(month);
    const cells = Array.from({ length: daysInMonth(start) }, (_, index) => `${month}-${pad(index + 1)}`);
    const canPrev = addMonths(month, -1) >= min.slice(0, 7);

    return (
        <View style={{ gap: 10 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                <Pressable accessibilityRole="button" accessibilityLabel="Previous month" disabled={!canPrev} onPress={() => setMonth(addMonths(month, -1))} style={{ width: target, height: target, alignItems: 'center', justifyContent: 'center', opacity: canPrev ? 1 : 0.3 }}>
                    <ChevronLeft size={22} color={c.text} />
                </Pressable>
                <Text v="title" accessibilityRole="header" accessibilityLiveRegion="polite">
                    {monthTitle(month)}
                </Text>
                <Pressable accessibilityRole="button" accessibilityLabel="Next month" onPress={() => setMonth(addMonths(month, 1))} style={{ width: target, height: target, alignItems: 'center', justifyContent: 'center' }}>
                    <ChevronRight size={22} color={c.text} />
                </Pressable>
            </View>
            <View style={{ flexDirection: 'row' }}>
                {WEEK.map((letter, index) => (
                    <View key={index} style={{ flex: 1, alignItems: 'center', paddingBottom: 6 }} accessibilityElementsHidden importantForAccessibility="no">
                        <Text v="label">{letter}</Text>
                    </View>
                ))}
            </View>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
                {Array.from({ length: start.getDay() }, (_, index) => (
                    <View key={`b${index}`} style={{ width: `${100 / 7}%` }} />
                ))}
                {cells.map((day) => {
                    const pickable = day >= min;
                    const picked = day === value;
                    const number = Number(day.slice(8));

                    return (
                        <View key={day} style={{ width: `${100 / 7}%`, padding: 2 }}>
                            <Pressable
                                accessibilityRole="button"
                                accessibilityLabel={`${WEEK_FULL[parseDate(day).getDay()]} ${number} ${monthTitle(month).split(' ')[0]}${picked ? ', picked' : ''}`}
                                accessibilityState={{ disabled: !pickable, selected: picked }}
                                disabled={!pickable}
                                onPress={() => onPick(day)}
                                style={{ minHeight: 48, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: picked ? c.accent : pickable ? c.line : 'transparent', backgroundColor: picked ? 'rgba(42,211,203,0.16)' : pickable ? c.surface : 'transparent' }}
                            >
                                <Text weight="semi" color={pickable ? c.text : c.field} style={{ fontSize: 16 }}>
                                    {number}
                                </Text>
                            </Pressable>
                        </View>
                    );
                })}
            </View>
        </View>
    );
}
