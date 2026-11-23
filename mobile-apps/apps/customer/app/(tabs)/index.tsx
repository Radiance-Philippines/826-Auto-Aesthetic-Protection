import { useRouter } from 'expo-router';
import { ArrowRight, CalendarDays, ClipboardList, Copy, Check, Gift, Share2 } from 'lucide-react-native';
import { View } from 'react-native';
import { api, type Home, type Showcase } from '@/api';
import { VehicleHead } from '@/features/VehicleCard';
import { BookingCard } from '@/features/BookingCard';
import { WorkPreview } from '@/features/Work';
import { useAuth } from '@/context/auth';
import { useCopied } from '@/hooks/useCopied';
import { useLoad, shortDate, radius, useTheme, Button, Card, Chip, Screen, Enter, PressScale, Skeleton, ErrorState, Text } from '@826/core';
import { shareReferral } from '@/lib/share';
import { STAGES } from '@/lib/stage';
import { bookingUnit } from '@/lib/unit';
import { Silhouette } from '@/ui/Silhouette';
import { StageLine } from '@/ui/StageLine';

export default function HomeScreen() {
    const c = useTheme();
    const router = useRouter();
    const { guest } = useAuth();
    const { data, error, loading, reload } = useLoad<Home>(() => api.home());
    // Our work is a bonus: if it fails to load, Home just leaves it out
    const work = useLoad<Showcase>(() => api.showcase());
    const [copied, copyCode] = useCopied();
    const first = (data?.guest.name ?? guest?.name ?? '').split(' ')[0];
    const shop = data?.inShop;

    return (
        <Screen brand title={first ? `Hi, ${first}` : 'Hi'} onRefresh={async () => {
                await Promise.all([reload(), work.reload()]);
            }}
            tabs
        >
            {loading ? (
                <>
                    <Skeleton height={200} />
                    <Skeleton height={130} />
                    <Skeleton height={150} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    {shop ? (
                        <Enter index={0}>
                            <PressScale accessibilityRole="button" accessibilityLabel={`In the shop now: ${bookingUnit(shop).name}, ${STAGES[shop.stage].label}. Open`} onPress={() => router.push(`/booking/${shop.id}`)}>
                                <Card style={{ gap: 16, padding: 18 }}>
                                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                                        <Text v="label" style={{ flexShrink: 1 }}>
                                            In the shop now
                                        </Text>
                                        <Chip label={STAGES[shop.stage].label} tone={STAGES[shop.stage].tone} />
                                    </View>
                                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
                                        <Silhouette type={shop.vehicle.type} width={92} height={54} />
                                        <View style={{ flex: 1 }}>
                                            <Text v="title" numberOfLines={2}>
                                                {bookingUnit(shop).name}
                                            </Text>
                                            <Text v="small">{bookingUnit(shop).sub}</Text>
                                        </View>
                                    </View>
                                    <StageLine stage={shop.stage} />
                                    {shop.readyOn ? (
                                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.surface2, borderRadius: radius.md, padding: 12 }}>
                                            <CalendarDays size={18} color={c.accent} />
                                            <Text style={{ flexShrink: 1, fontSize: 14 }}>
                                                Ready by {shortDate(shop.readyOn)}
                                                {shop.branch ? ` · ${shop.branch.name}` : ''}
                                            </Text>
                                        </View>
                                    ) : null}
                                </Card>
                            </PressScale>
                        </Enter>
                    ) : data.nextBooking ? (
                        <Enter index={0} style={{ gap: 8 }}>
                            <Text v="label">Coming up</Text>
                            <BookingCard booking={data.nextBooking} />
                        </Enter>
                    ) : (
                        <Card style={{ gap: 10 }}>
                            <Text v="title">Nothing in the shop right now</Text>
                            <Text color={c.muted}>Reserve a drop-off date and we’ll show your unit’s progress here.</Text>
                            <Button label="Book a service" icon={CalendarDays} onPress={() => router.push('/book')} />
                        </Card>
                    )}

                    {data.referral.enabled && data.referral.code ? (
                        <Enter index={1}>
                            <Card style={{ gap: 14 }}>
                                <View style={{ flexDirection: 'row', gap: 12 }}>
                                    <Gift size={22} color={c.gold} />
                                    <Text color={c.muted} style={{ flex: 1 }}>
                                        Share your code. Friends get a discount, you earn points.
                                    </Text>
                                </View>
                                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10, borderRadius: radius.md, borderWidth: 1, borderColor: c.line, backgroundColor: c.bg, paddingVertical: 10, paddingLeft: 16, paddingRight: 10 }}>
                                    <Text weight="bold" accessibilityLabel={`Your code ${data.referral.code}`} style={{ fontSize: 18, letterSpacing: 1, flexShrink: 1 }}>
                                        {data.referral.code}
                                    </Text>
                                </View>
                                <View style={{ flexDirection: 'row', gap: 10 }}>
                                    <Button small kind="secondary" icon={copied ? Check : Copy} label={copied ? 'Copied' : 'Copy code'} onPress={() => void copyCode(data.referral.code as string)} style={{ flex: 1 }} />
                                    <Button small icon={Share2} label="Share" onPress={() => void shareReferral(data.referral.code as string, data.referral.discountLabel)} style={{ flex: 1 }} />
                                </View>
                            </Card>
                        </Enter>
                    ) : null}

                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 4 }}>
                        <Text v="title" accessibilityRole="header" style={{ fontSize: 19 }}>
                            My garage
                        </Text>
                        <Button small kind="link" icon={ArrowRight} label="See all" onPress={() => router.push('/garage')} />
                    </View>
                    {data.garage.length ? (
                        <View style={{ gap: 12 }}>
                            {data.garage.slice(0, 6).map((vehicle, index) => (
                                <Enter key={vehicle.id} index={index + 2}>
                                    <PressScale accessibilityRole="button" accessibilityLabel={`Open ${vehicle.model}`} onPress={() => router.push(`/vehicle/${vehicle.id}`)}>
                                        <Card style={{ padding: 14 }}>
                                            <VehicleHead vehicle={vehicle} width={84} />
                                        </Card>
                                    </PressScale>
                                </Enter>
                            ))}
                        </View>
                    ) : (
                        <Card>
                            <Text color={c.muted}>Units you book are kept here for next time.</Text>
                        </Card>
                    )}

                    <WorkPreview work={work} onSeeAll={() => router.push('/work')} />

                    <Button kind="secondary" icon={ClipboardList} label="My bookings" onPress={() => router.push('/bookings')} />
                </>
            ) : null}
        </Screen>
    );
}
