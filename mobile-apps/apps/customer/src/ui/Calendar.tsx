import { ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, View } from 'react-native';
import { api, type DayState, type MonthAvailability } from '@/api';
import type { JobQuery } from '@/api/endpoints';
import { addMonths, daysInMonth, manilaToday, monthKey, monthStart, monthTitle, pad, parseDate, radius, target, useTheme, Button, Text } from '@826/core';

const WEEK = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const WEEK_FULL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

type Props = {
    /** What the open days are for; left out when `range` is given */
    job?: JobQuery;
    /** Home service: every day from `first` to `last` is open, nothing is fetched */
    range?: { first: string; last: string };
    value: string | null;
    onPick: (date: string) => void;
    /** Every loaded month, so the screen can work out the ready date */
    onMonths?: (months: Record<string, MonthAvailability>) => void;
};

/** A month where every day inside the range is open */
function rangeMonth(month: string, { first, last }: { first: string; last: string }): MonthAvailability {
    const days: Record<string, DayState> = {};

    for (let d = 1; d <= daysInMonth(monthStart(month)); d++) {
        const day = `${month}-${pad(d)}`;
        days[day] = day >= first && day <= last ? 'open' : 'closed';
    }

    return { month, first, last, days, estimate: null };
}

/** The days of a month with room (open), fully booked, or closed, from GET /api/availability. Like the site's calendar. */
export function Calendar({ job, range, value, onPick, onMonths }: Props) {
    const c = useTheme();
    const [month, setMonth] = useState(() => (value ?? manilaToday()).slice(0, 7));
    const [months, setMonths] = useState<Record<string, MonthAvailability>>({});
    const [state, setState] = useState<'loading' | 'ready' | 'failed'>(range ? 'ready' : 'loading');
    const jobKey = JSON.stringify(job ?? range);
    const loaded = useRef('');

    const load = useCallback(
        async (key: string) => {
            if (!job) {
                return;
            }

            setState('loading');
            const result = await api.availability(job, key);

            if (result.kind !== 'ok') {
                setState('failed');

                return;
            }

            setMonths((previous) => ({ ...previous, [key]: result.data }));
            setState('ready');
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [jobKey],
    );

    useEffect(() => {
        onMonths?.(months);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [months]);

    useEffect(() => {
        // A new job (other branch or services) starts over
        if (loaded.current !== jobKey) {
            loaded.current = jobKey;
            setMonths({});
        }

        void load(month);
    }, [month, jobKey, load]);

    const data = range ? rangeMonth(month, range) : months[month];
    const any = range ? data : Object.values(months)[0];
    const first = data?.first ?? any?.first;
    const last = data?.last ?? any?.last;
    const start = monthStart(month);
    const blanks = start.getDay();
    const cells = Array.from({ length: daysInMonth(start) }, (_, index) => `${month}-${pad(index + 1)}`);
    const canPrev = first ? addMonths(month, -1) >= first.slice(0, 7) : month > monthKey(new Date());
    const canNext = last ? addMonths(month, 1) <= last.slice(0, 7) : true;
    const nav = (delta: number) => setMonth((current) => addMonths(current, delta));

    return (
        <View style={{ gap: 10 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                <Pressable accessibilityRole="button" accessibilityLabel="Previous month" disabled={!canPrev} onPress={() => nav(-1)} style={{ width: target, height: target, alignItems: 'center', justifyContent: 'center', opacity: canPrev ? 1 : 0.3 }}>
                    <ChevronLeft size={22} color={c.text} />
                </Pressable>
                <Text v="title" accessibilityRole="header" accessibilityLiveRegion="polite">
                    {monthTitle(month)}
                </Text>
                <Pressable accessibilityRole="button" accessibilityLabel="Next month" disabled={!canNext} onPress={() => nav(1)} style={{ width: target, height: target, alignItems: 'center', justifyContent: 'center', opacity: canNext ? 1 : 0.3 }}>
                    <ChevronRight size={22} color={c.text} />
                </Pressable>
            </View>

            {state === 'failed' ? (
                <View style={{ alignItems: 'center', gap: 10, paddingVertical: 18 }}>
                    <Text color={c.muted}>We couldn’t load the open days.</Text>
                    <Button icon={RefreshCw} label="Try again" kind="secondary" onPress={() => load(month)} />
                </View>
            ) : (
                <View style={{ minHeight: 6 * 52 + 20 }}>
                    <View style={{ flexDirection: 'row' }}>
                        {WEEK.map((letter, index) => (
                            <View key={index} style={{ flex: 1, alignItems: 'center', paddingBottom: 6 }} accessibilityElementsHidden importantForAccessibility="no">
                                <Text v="label">{letter}</Text>
                            </View>
                        ))}
                    </View>
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap', opacity: state === 'loading' ? 0.4 : 1 }}>
                        {Array.from({ length: blanks }, (_, index) => (
                            <View key={`b${index}`} style={{ width: `${100 / 7}%` }} />
                        ))}
                        {cells.map((day) => {
                            const dayState = state === 'loading' || !data ? 'closed' : (data.days[day] ?? 'closed');
                            const pickable = state === 'ready' && dayState === 'open';
                            const picked = day === value;
                            const number = Number(day.slice(8));
                            const name = WEEK_FULL[parseDate(day).getDay()];

                            return (
                                <View key={day} style={{ width: `${100 / 7}%`, padding: 2 }}>
                                    <Pressable
                                        accessibilityRole="button"
                                        accessibilityLabel={`${name} ${number} ${monthTitle(month).split(' ')[0]}, ${picked ? 'picked' : dayState === 'open' ? 'open' : dayState === 'full' ? 'fully booked' : 'closed'}`}
                                        accessibilityState={{ disabled: !pickable, selected: picked }}
                                        disabled={!pickable}
                                        onPress={() => onPick(day)}
                                        style={{ minHeight: 52, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: picked ? c.accent : pickable ? c.line : 'transparent', backgroundColor: picked ? c.accentSoft : pickable ? c.surface : 'transparent' }}
                                    >
                                        <Text weight="semi" color={pickable ? c.text : c.field} style={{ fontSize: 16 }}>
                                            {number}
                                        </Text>
                                        {dayState === 'full' ? (
                                            <Text color={c.danger} style={{ fontSize: 10, lineHeight: 12 }}>
                                                Full
                                            </Text>
                                        ) : null}
                                    </Pressable>
                                </View>
                            );
                        })}
                    </View>
                    {state === 'loading' ? <ActivityIndicator color={c.accent} style={{ position: 'absolute', top: '45%', alignSelf: 'center' }} /> : null}
                </View>
            )}
            {range ? null : (
                <View style={{ flexDirection: 'row', gap: 16 }}>
                    <Text v="small">Open days are outlined</Text>
                    <Text v="small">Full = fully booked</Text>
                </View>
            )}
        </View>
    );
}
