import { useLocalSearchParams } from 'expo-router';
import { ChevronDown, ShieldCheck } from 'lucide-react-native';
import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { api, type WarrantyService, type WarrantyUnit } from '@/api';
import { useLoad, dayMonthYear, radius, target, useTheme, Card, Chip, Screen, Sheet, Skeleton, EmptyState, ErrorState, Text } from '@826/core';

const normalise = (data: WarrantyUnit[] | { units: WarrantyUnit[] }): WarrantyUnit[] => (Array.isArray(data) ? data : (data.units ?? []));

function ServiceCard({ service }: { service: WarrantyService }) {
    const c = useTheme();
    const active = service.status === 'active';

    return (
        <Card style={{ gap: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                <Text v="title" style={{ flexShrink: 1 }}>
                    {service.name}
                </Text>
                {service.status === 'none' ? null : <Chip label={active ? 'Active' : 'Expired'} tone={active ? 'green' : 'neutral'} />}
            </View>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                <ShieldCheck size={16} color={active ? c.tone.green.fg : c.steel} />
                <Text v="small" style={{ flexShrink: 1 }}>
                    {service.status === 'none' ? `Finished ${dayMonthYear(service.finishedOn)}. No warranty for this service.` : service.endsOn ? `Covered until ${dayMonthYear(service.endsOn)}` : `Finished ${dayMonthYear(service.finishedOn)}`}
                    {service.months && active ? ` · ${service.months} months` : ''}
                </Text>
            </View>
            {active && service.backjobTypesCovered.length ? (
                <View style={{ gap: 6 }}>
                    <Text v="small">Backjobs covered</Text>
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                        {service.backjobTypesCovered.map((type) => (
                            <View key={type} style={{ backgroundColor: c.surface2, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 6 }}>
                                <Text style={{ fontSize: 13 }} weight="medium">
                                    {type}
                                </Text>
                            </View>
                        ))}
                    </View>
                </View>
            ) : null}
            {service.status === 'expired' ? <Text v="small">Backjobs are no longer covered for this service.</Text> : null}
        </Card>
    );
}

/** C5: what is covered on each unit, built by the server from finished jobs. Nothing here is guessed. */
export default function WarrantyScreen() {
    const c = useTheme();
    const { vehicleId } = useLocalSearchParams<{ vehicleId?: string }>();
    const { data, error, loading, reload } = useLoad<WarrantyUnit[], WarrantyUnit[] | { units: WarrantyUnit[] }>(() => api.warranty(), normalise);
    // A unit without a saved record has no id: units are told apart by their place in the list
    const [picked, setPicked] = useState<number | null>(null);
    const [open, setOpen] = useState(false);
    const unit = (picked !== null ? data?.[picked] : data?.find((u) => vehicleId && String(u.vehicleId) === vehicleId)) ?? data?.[0];

    return (
        <Screen back="Garage" title="Warranty" onRefresh={reload}>
            {loading ? (
                <Skeleton height={180} />
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data && unit ? (
                <>
                    <Pressable accessibilityRole="button" accessibilityLabel={`Unit: ${unit.vehicleLabel}. Change`} onPress={() => setOpen(true)} style={{ minHeight: 60, borderRadius: radius.lg, borderWidth: 1, borderColor: c.line, backgroundColor: c.surface, paddingHorizontal: 16, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                        <Text v="title" style={{ flexShrink: 1 }}>
                            {unit.vehicleLabel}
                        </Text>
                        {data.length > 1 ? <ChevronDown size={20} color={c.muted} /> : null}
                    </Pressable>
                    <Sheet open={open && data.length > 1} onClose={() => setOpen(false)} title="Pick a unit">
                        {data.map((u, index) => (
                            <Pressable
                                key={`${u.vehicleId}-${index}`}
                                accessibilityRole="radio"
                                accessibilityState={{ selected: u === unit }}
                                onPress={() => {
                                    setPicked(index);
                                    setOpen(false);
                                }}
                                style={{ minHeight: target, justifyContent: 'center' }}
                            >
                                <Text weight={u === unit ? 'bold' : 'regular'} style={{ fontSize: 16 }}>
                                    {u.vehicleLabel}
                                </Text>
                            </Pressable>
                        ))}
                    </Sheet>
                    {unit.services.length === 0 ? <EmptyState title="No finished work yet" body="Warranty shows here once a job on this unit is finished." /> : null}
                    {unit.services.map((service) => (
                        <ServiceCard key={`${service.name}-${service.finishedOn}`} service={service} />
                    ))}
                    <Text v="small">Seeing a problem in a covered service? Call or message the branch that did the work.</Text>
                </>
            ) : data ? (
                <EmptyState title="No finished work yet" body="Warranty shows here once a job on one of your units is finished." />
            ) : null}
        </Screen>
    );
}
