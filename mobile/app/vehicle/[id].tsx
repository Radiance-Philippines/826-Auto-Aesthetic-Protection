import { useLocalSearchParams, useRouter } from 'expo-router';
import { View } from 'react-native';
import { CalendarPlus, ShieldCheck } from 'lucide-react-native';
import { api, type VehicleDetail } from '@/api';
import { VehicleHead } from '@/features/VehicleCard';
import { useLoad } from '@/hooks/useLoad';
import { dayMonthYear } from '@/lib/dates';
import { peso } from '@/lib/money';
import { STAGES } from '@/lib/stage';
import { useTheme } from '@/context/theme';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Chip } from '@/ui/Chip';
import { Screen } from '@/ui/Screen';
import { Enter, PressScale } from '@/ui/motion';
import { Skeleton } from '@/ui/Skeleton';
import { EmptyState, ErrorState } from '@/ui/States';
import { Text } from '@/ui/Text';


/** One unit: its details and every job 826 has done on it (own units only; anything else is a 404) */
export default function VehicleScreen() {
    const c = useTheme();
    const router = useRouter();
    const { id } = useLocalSearchParams<{ id: string }>();
    const { data, error, loading, reload } = useLoad<VehicleDetail>(() => api.vehicle(Number(id)));

    return (
        <Screen back="Garage" title="Your unit" onRefresh={reload}>
            {loading ? (
                <Skeleton height={160} />
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    <Card style={{ gap: 14 }}>
                        <VehicleHead vehicle={data.vehicle} />
                        <View style={{ flexDirection: 'row', gap: 10, flexWrap: 'wrap' }}>
                            <Button small icon={CalendarPlus} label="Book again" onPress={() => router.push({ pathname: '/book', params: { vehicleId: String(data.vehicle.id) } })} />
                            <Button small kind="secondary" icon={ShieldCheck} label="Warranty" onPress={() => router.push({ pathname: '/warranty', params: { vehicleId: String(data.vehicle.id) } })} />
                        </View>
                        <Text v="small">{data.vehicle.sizeReviewed ? 'Size reviewed' : 'We review its size after you book'}</Text>
                    </Card>
                    <Text v="title" accessibilityRole="header">
                        History
                    </Text>
                    {data.history.length === 0 ? <EmptyState title="No work yet" body="Jobs on this unit show here." /> : null}
                    {data.history.map((h, index) => {
                        const stage = STAGES[h.status as keyof typeof STAGES];
                        const body = (
                            <Card style={{ gap: 8 }}>
                                <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8, alignItems: 'center' }}>
                                    <Text weight="bold" style={{ flexShrink: 1 }}>
                                        {h.services.join(', ')}
                                    </Text>
                                    {stage ? <Chip label={stage.label} tone={stage.tone} /> : null}
                                </View>
                                <Text v="small">
                                    {dayMonthYear(h.date)}
                                    {h.branch ? ` · ${h.branch}` : ''} · Ref. {h.reference}
                                </Text>
                                {h.price !== null ? <Text weight="semi">{peso(h.price)}</Text> : <Text v="small" color={c.muted}>Price after we review your unit</Text>}
                            </Card>
                        );

                        return h.id ? (
                            <Enter key={h.reference} index={index}>
                                <PressScale accessibilityRole="button" accessibilityLabel={`Open booking ${h.reference}`} onPress={() => router.push(`/booking/${h.id}`)}>
                                    {body}
                                </PressScale>
                            </Enter>
                        ) : (
                            <Enter key={h.reference} index={index}>{body}</Enter>
                        );
                    })}
                </>
            ) : null}
        </Screen>
    );
}
