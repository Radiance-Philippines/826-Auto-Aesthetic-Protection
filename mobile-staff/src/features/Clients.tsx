import { useRouter } from 'expo-router';
import { ChevronRight, MessageCircle, Phone, Save, Search } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Linking, Pressable, View } from 'react-native';
import { api, problemText, type CustomerRow } from '@/api';
import { useUser } from '@/context/auth';
import { useLoad } from '@/hooks/useLoad';
import { canSeeContact, canSeeMoney } from '@/lib/access';
import { shortDate } from '@/lib/dates';
import { peso } from '@/lib/money';
import { stageChip } from '@/lib/stage';
import { initials } from '@/lib/unit';
import { c } from '@/theme';
import { Avatar } from '@/ui/Pills';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Chip } from '@/ui/Chip';
import { Field } from '@/ui/Field';
import { RoleChip } from '@/ui/Header';
import { Screen } from '@/ui/Screen';
import { Skeleton } from '@/ui/Skeleton';
import { EmptyState, ErrorState, FormError } from '@/ui/States';
import { Text } from '@/ui/Text';

/** S4: customers of the person's branches, searchable by name, phone or plate */
export function ClientsScreen() {
    const user = useUser();
    const router = useRouter();
    const [text, setText] = useState('');
    const [q, setQ] = useState('');
    const [extra, setExtra] = useState<CustomerRow[]>([]);
    const [page, setPage] = useState(1);
    const [more, setMore] = useState<boolean | null>(null);
    const [loadingMore, setLoadingMore] = useState(false);
    const { data, error, loading, reload } = useLoad(() => api.customers(q, 1), q);

    // A search waits for a pause in typing
    useEffect(() => {
        const timer = setTimeout(() => setQ(text.trim()), 350);

        return () => clearTimeout(timer);
    }, [text]);

    useEffect(() => {
        setExtra([]);
        setPage(1);
        setMore(null);
    }, [q]);

    const rows = [...(data?.data ?? []), ...extra];
    const hasMore = more ?? data?.hasMore ?? false;

    const loadMore = async () => {
        setLoadingMore(true);
        const result = await api.customers(q, page + 1);
        setLoadingMore(false);

        if (result.kind === 'ok') {
            setExtra((previous) => [...previous, ...result.data.data]);
            setPage(page + 1);
            setMore(result.data.hasMore);
        }
    };

    return (
        <Screen tabs top={<RoleChip user={user} />} title="Clients" onRefresh={reload}>
            <Field label="Search" icon={Search} value={text} onChangeText={setText} placeholder="Name, phone or plate" autoCapitalize="none" autoCorrect={false} returnKeyType="search" />
            {loading ? (
                <>
                    <Skeleton height={84} style={{ borderRadius: 16 }} />
                    <Skeleton height={84} style={{ borderRadius: 16 }} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : rows.length === 0 ? (
                <EmptyState title={q ? 'No match' : 'No clients yet'} body={q ? 'Try a name, a phone number or a plate.' : 'Customers show here once they book.'} />
            ) : (
                rows.map((row) => (
                    <Pressable key={row.id} accessibilityRole="button" accessibilityLabel={`${row.name}, ${row.unitsCount} ${row.unitsCount === 1 ? 'unit' : 'units'}. Open`} onPress={() => router.push(`/client/${row.id}`)}>
                        <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                            <Avatar text={initials(row.name)} />
                            <View style={{ flex: 1, gap: 2 }}>
                                <Text v="title">{row.name}</Text>
                                <Text v="small">{[`${row.unitsCount} ${row.unitsCount === 1 ? 'unit' : 'units'}`, row.lastVisitOn ? `last visit ${shortDate(row.lastVisitOn)}` : null].filter(Boolean).join(' · ')}</Text>
                            </View>
                            <ChevronRight size={20} color={c.muted} />
                        </Card>
                    </Pressable>
                ))
            )}
            {hasMore ? <Button kind="secondary" label="Show more" loading={loadingMore} onPress={loadMore} /> : null}
        </Screen>
    );
}

/** S5: one client: contact, units, bookings, notes, and the Messenger chat when there is one */
export function ClientScreen({ id }: { id: number }) {
    const user = useUser();
    const router = useRouter();
    const { data, error, loading, reload } = useLoad(() => api.customer(id));
    const [notes, setNotes] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    if (!data) {
        return (
            <Screen back="Clients" onRefresh={reload}>
                {loading ? <Skeleton height={200} style={{ borderRadius: 16 }} /> : error ? <ErrorState result={error} onRetry={reload} /> : null}
            </Screen>
        );
    }

    const contact = canSeeContact(user);
    const money = canSeeMoney(user);
    const draft = notes ?? data.notes ?? '';

    const save = async () => {
        setBusy(true);
        setProblem(null);
        const result = await api.updateCustomer(id, { notes: draft });
        setBusy(false);

        if (result.kind === 'ok') {
            setNotes(null);
            setSaved(true);
            await reload();
        } else {
            setProblem(problemText(result));
        }
    };

    return (
        <Screen back="Clients" title={data.name} onRefresh={reload}>
            <View style={{ flexDirection: 'row', gap: 10 }}>
                {contact && data.phone ? <Button style={{ flex: 1 }} icon={Phone} label="Call" onPress={() => void Linking.openURL(`tel:${data.phone!.replace(/\s+/g, '')}`)} /> : null}
                {data.messenger ? <Button style={{ flex: 1 }} kind="secondary" icon={MessageCircle} label="Messenger" onPress={() => router.push(`/thread/${data.messenger!.conversationId}`)} /> : null}
            </View>
            {contact && (data.phone || data.email) ? (
                <Card style={{ gap: 4 }}>
                    {data.phone ? <Text>{data.phone}</Text> : null}
                    {data.email ? <Text color={c.muted}>{data.email}</Text> : null}
                </Card>
            ) : null}
            {data.referralCode ? (
                <Card style={{ gap: 4 }}>
                    <Text v="label">Referral code</Text>
                    <Text weight="bold">{data.referralCode}</Text>
                    {data.pointsBalance !== null ? <Text color={c.muted}>{data.pointsBalance} points</Text> : null}
                </Card>
            ) : null}

            <Text v="label">Units</Text>
            {data.units.length === 0 ? <Text color={c.muted}>No units yet.</Text> : null}
            {data.units.map((unit) => (
                <Card key={unit.id} style={{ gap: 2 }}>
                    <Text v="title">{unit.label}</Text>
                    <Text v="small">{[unit.plate, unit.size, `${unit.historyCount} ${unit.historyCount === 1 ? 'visit' : 'visits'}`].filter(Boolean).join(' · ')}</Text>
                </Card>
            ))}

            <Text v="label">Bookings</Text>
            {data.bookings.length === 0 ? <Text color={c.muted}>No bookings yet.</Text> : null}
            {data.bookings.map((booking) => (
                <Pressable key={booking.id} accessibilityRole="button" accessibilityLabel={`${booking.unit ?? 'Booking'}, ${booking.statusLabel}. Open`} onPress={() => router.push(`/unit/${booking.id}`)}>
                    <Card style={{ gap: 6 }}>
                        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                            <Text v="title" style={{ flex: 1 }}>
                                {booking.unit ?? 'Booking'}
                            </Text>
                            <Chip label={stageChip(booking.status).label} tone={stageChip(booking.status).tone} />
                        </View>
                        <Text v="small">{[booking.servicesLabel, booking.dropOffOn ? shortDate(booking.dropOffOn) : null, booking.branch].filter(Boolean).join(' · ')}</Text>
                        {typeof booking.total === 'number' && money ? <Text weight="semi">{peso(booking.total)}</Text> : null}
                    </Card>
                </Pressable>
            ))}

            <Text v="label">Notes</Text>
            {data.can.edit ? (
                <>
                    <Field label="Notes about this client" value={draft} onChangeText={(text) => { setNotes(text); setSaved(false); }} multiline />
                    <FormError>{problem}</FormError>
                    {saved ? <Text color={c.tone.green.fg} accessibilityLiveRegion="polite">Saved.</Text> : null}
                    <Button label="Save notes" icon={Save} disabled={notes === null} loading={busy} onPress={save} />
                </>
            ) : (
                <Text color={data.notes ? c.text : c.muted}>{data.notes ?? 'No notes.'}</Text>
            )}
        </Screen>
    );
}
