import { useLocalSearchParams, useRouter } from 'expo-router';
import { CalendarDays, MessageCircle, Phone, Star } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Linking, View } from 'react-native';
import { api, problemText, type CustomerBooking } from '@/api';
import { PaymentPanel } from '@/features/PaymentPanel';
import { useCatalog } from '@/context/catalog';
import { useLoad } from '@/hooks/useLoad';
import { longDate, shortDate } from '@/lib/dates';
import { peso } from '@/lib/money';
import { isEnded, STAGES, timeline } from '@/lib/stage';
import { bookingUnit, serviceLine } from '@/lib/unit';
import { c, radius } from '@/theme';
import { Button } from '@/ui/Button';
import { Calendar } from '@/ui/Calendar';
import { Card } from '@/ui/Card';
import { Chip } from '@/ui/Chip';
import { Field } from '@/ui/Field';
import { Screen } from '@/ui/Screen';
import { Sheet } from '@/ui/Sheet';
import { Silhouette } from '@/ui/Silhouette';
import { Skeleton } from '@/ui/Skeleton';
import { ErrorState, FormError } from '@/ui/States';
import { Stars } from '@/ui/Stars';
import { Text } from '@/ui/Text';

/** C3 "Your unit": where the job is, what it costs (once reviewed), paying, moving, cancelling, reviewing */
export default function BookingScreen() {
    const router = useRouter();
    const { id } = useLocalSearchParams<{ id: string }>();
    const { catalog } = useCatalog();
    const { data, error, loading, reload } = useLoad<CustomerBooking, { booking: CustomerBooking }>(() => api.booking(Number(id)), (raw) => raw.booking);
    const [booking, setBooking] = useState<CustomerBooking | null>(null);
    const [sheet, setSheet] = useState<'cancel' | 'move' | null>(null);
    const [reason, setReason] = useState('');
    const [day, setDay] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => setBooking(data), [data]);

    const rules = catalog?.booking;
    const b = booking;

    const run = async (action: 'cancel' | 'move') => {
        if (!b) return;
        if (action === 'move' && !day) {
            setProblem('Pick a day first.');

            return;
        }

        setBusy(true);
        setProblem(null);
        const result = action === 'cancel' ? await api.cancel(b.id, reason) : await api.reschedule(b.id, day as string);
        setBusy(false);

        if (result.kind === 'ok') {
            setBooking(result.data.booking);
            setSheet(null);
            setReason('');
            setDay(null);
        } else {
            setProblem(problemText(result));
        }
    };

    const unit = b ? bookingUnit(b) : null;
    const stage = b ? STAGES[b.stage] : null;
    const rows = b ? timeline(b) : [];
    const message = b?.branch?.messenger ?? null;

    return (
        <Screen back="Home" title="Your unit" onRefresh={reload}>
            {loading ? (
                <>
                    <Skeleton height={80} />
                    <Skeleton height={220} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : b && unit && stage ? (
                <>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                        <Silhouette type={b.vehicle.type} width={84} height={52} />
                        <View style={{ flex: 1, gap: 4 }}>
                            <Text v="title" numberOfLines={2}>
                                {unit.name}
                            </Text>
                            <Text v="small">{unit.sub}</Text>
                        </View>
                    </View>
                    <Chip label={stage.label} tone={stage.tone} />

                    {b.readyOn && !isEnded(b.stage) && b.kind === 'onsite' ? (
                        <View style={{ backgroundColor: 'rgba(42,211,203,0.12)', borderColor: 'rgba(42,211,203,0.3)', borderWidth: 1, borderRadius: radius.lg, padding: 16, flexDirection: 'row', gap: 12 }}>
                            <CalendarDays size={22} color={c.accent} />
                            <View style={{ flex: 1 }}>
                                <Text v="title">{`Ready by ${shortDate(b.readyOn)}`}</Text>
                                <Text v="small">{[b.estimate ? `Estimated ${b.estimate.label.replace('about ', '')}` : null, serviceLine(b), b.branch?.name].filter(Boolean).join(' · ')}</Text>
                            </View>
                        </View>
                    ) : (
                        <Card>
                            <Text color={c.muted}>{stage.body}</Text>
                        </Card>
                    )}

                    {b.kind === 'home' ? (
                        <Card style={{ gap: 6 }}>
                            <Text v="label">Home service</Text>
                            {b.preferredDate ? <Text>{`You asked for ${longDate(b.preferredDate)}${b.preferredWindow && rules ? ` · ${rules.windows[b.preferredWindow]}` : ''}`}</Text> : null}
                            {b.address ? <Text v="small">{[b.address, b.area].filter(Boolean).join(', ')}</Text> : null}
                        </Card>
                    ) : null}

                    {rows.length ? (
                        <Card style={{ gap: 0 }} accessibilityLabel="Progress">
                            {rows.map((row, index) => (
                                <View key={row.key} style={{ flexDirection: 'row', gap: 14 }}>
                                    <View style={{ alignItems: 'center', width: 14 }}>
                                        <View style={{ width: 14, height: 14, borderRadius: 7, marginTop: 3, backgroundColor: row.state === 'next' ? c.surface2 : c.accent }} />
                                        {index < rows.length - 1 ? <View style={{ flex: 1, width: 2, minHeight: 28, backgroundColor: rows[index + 1].state === 'next' ? c.surface2 : c.accent }} /> : null}
                                    </View>
                                    <View style={{ flex: 1, paddingBottom: index < rows.length - 1 ? 14 : 0 }}>
                                        <Text weight="bold" color={row.state === 'next' ? c.muted : c.text}>
                                            {row.label}
                                        </Text>
                                        {row.detail ? (
                                            <Text v="small" color={row.state === 'current' ? c.accent : c.muted}>
                                                {row.detail}
                                            </Text>
                                        ) : null}
                                    </View>
                                </View>
                            ))}
                        </Card>
                    ) : null}

                    {b.branch ? (
                        <Card style={{ gap: 12 }}>
                            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
                                <View style={{ flex: 1 }}>
                                    <Text weight="bold">{`826 ${b.branch.name}`}</Text>
                                    <Text v="small">Questions about your unit?</Text>
                                </View>
                                {message ? <Button small icon={MessageCircle} label="Message" onPress={() => void Linking.openURL(message)} /> : null}
                                {b.branch.phoneHref ? <Button small kind="secondary" icon={Phone} label="Call" onPress={() => void Linking.openURL(`tel:${b.branch?.phoneHref}`)} /> : null}
                            </View>
                        </Card>
                    ) : null}

                    <Card style={{ gap: 10 }}>
                        <Text v="label">Price</Text>
                        {b.price.status === 'set' && b.price.total !== null ? (
                            <>
                                {b.price.items.map((item) => (
                                    <View key={item.name} style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                                        <Text style={{ flexShrink: 1 }}>{item.name}</Text>
                                        {item.price !== null ? <Text>{peso(item.price)}</Text> : null}
                                    </View>
                                ))}
                                {b.price.discount ? (
                                    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                                        <Text color={c.tone.green.fg}>Referral discount</Text>
                                        <Text color={c.tone.green.fg}>{`-${peso(b.price.discount)}`}</Text>
                                    </View>
                                ) : null}
                                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                                    <Text weight="bold">Total</Text>
                                    <Text v="number">{peso(b.price.total)}</Text>
                                </View>
                                {b.balance !== null ? <Text v="small">{`Balance at the branch: ${peso(b.balance)}`}</Text> : null}
                            </>
                        ) : (
                            <>
                                <Text weight="semi">We’re reviewing your unit’s size</Text>
                                <Text v="small">Once we know its size, the price shows here and we email you.</Text>
                            </>
                        )}
                    </Card>

                    {b.stage === 'awaiting_payment' ? <PaymentPanel booking={b} onUpdated={setBooking} /> : null}
                    {b.stage === 'payment_review' ? (
                        <Card style={{ gap: 4 }}>
                            <Text weight="bold">We’re checking your payment</Text>
                            <Text v="small">{`Your ${b.payment.method ?? 'payment'} screenshot is in. The branch confirms your date once they’ve checked it.`}</Text>
                        </Card>
                    ) : null}

                    {b.canReview ? <Button icon={Star} label="Write a review" onPress={() => router.push(`/review/${b.id}`)} testID="write-review" /> : null}
                    {b.review ? (
                        <Card style={{ gap: 6 }}>
                            <Text v="label">Your review</Text>
                            <Stars value={b.review.rating} size={20} />
                            <Text>{b.review.body}</Text>
                            <Text v="small">{b.review.status === 'pending' ? 'Waiting for approval' : b.review.status === 'approved' ? 'Shown on the site' : 'Not shown on the site'}</Text>
                        </Card>
                    ) : null}

                    {b.canReschedule && rules?.rescheduleEnabled ? (
                        <>
                            <Button kind="secondary" label="Move to another date" onPress={() => { setProblem(null); setSheet('move'); }} />
                            <Text v="small">{`You can move a booking online ${rules.maxReschedules === 1 ? 'once' : `up to ${rules.maxReschedules} times`}, up to ${rules.rescheduleCutoffHours} hours before drop-off.`}</Text>
                        </>
                    ) : null}
                    {b.canCancel ? (
                        <>
                            <Button kind="danger" label="Cancel booking" onPress={() => { setProblem(null); setSheet('cancel'); }} testID="cancel-booking" />
                            {rules ? <Text v="small">{`You can cancel online up to ${rules.cancelCutoffHours} hours before drop-off.`}</Text> : null}
                        </>
                    ) : null}

                    <Text v="small" style={{ textAlign: 'center' }}>{`Ref. ${b.reference}`}</Text>

                    <Sheet open={sheet === 'cancel'} onClose={() => setSheet(null)} title="Cancel this booking?">
                        <Text color={c.muted}>{`Your date${b.dropOffOn ? ` (${shortDate(b.dropOffOn)})` : ''} will open up for others. This can’t be undone.`}</Text>
                        <Field label="Reason" optional value={reason} onChangeText={setReason} multiline hint="It helps the branch." />
                        <FormError>{problem}</FormError>
                        <Button kind="danger" label="Yes, cancel it" loading={busy} onPress={() => run('cancel')} />
                        <Button kind="secondary" label="Keep it" onPress={() => setSheet(null)} />
                    </Sheet>
                    <Sheet open={sheet === 'move'} onClose={() => setSheet(null)} title="Move your booking">
                        <Text color={c.muted}>{b.estimate ? `Same services, ${b.estimate.label} at the shop. Pick a new date.` : 'Same services. Pick a new date.'}</Text>
                        {sheet === 'move' ? <Calendar job={{ kind: 'move', booking: b.id }} value={day} onPick={setDay} /> : null}
                        <FormError>{problem}</FormError>
                        <Button label="Move to this date" loading={busy} onPress={() => run('move')} />
                        <Button kind="secondary" label="Keep the current date" onPress={() => setSheet(null)} />
                    </Sheet>
                </>
            ) : null}
        </Screen>
    );
}
