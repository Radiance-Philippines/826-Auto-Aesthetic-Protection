import { Check, Ruler, X } from 'lucide-react-native';
import { Image } from 'expo-image';
import { useEffect, useState, type ReactNode } from 'react';
import { View } from 'react-native';
import { api, problemText, type ApiResult, type Card as UnitCard, type UnitDetail, type VehicleSize } from '../api';
import { useUser } from '../context/auth';
import { useLoad, longDate, manilaToday, shortDate, peso, c, Button, Card, Chip, Field, Screen, Sheet, Skeleton, EmptyState, ErrorState, FormError, Text } from '@826/core';
import { canSeeMoney } from '../lib/access';
import { getToken } from '../lib/storage';
import { stageChip } from '../lib/stage';
import { unitName, unitSub } from '../lib/unit';
import { BranchBar } from '../ui/Branches';
import { TeamTop } from '../ui/Header';
import { API_URL } from '../lib/config';

function Section({ title, count, children }: { title: string; count: number; children: ReactNode }) {
    return (
        <View style={{ gap: 10 }}>
            <Text v="label" accessibilityRole="header">
                {title} · {count}
            </Text>
            {children}
        </View>
    );
}

function Row({ item, children }: { item: UnitCard; children?: ReactNode }) {
    const stage = stageChip(item.stage);

    return (
        <Card style={{ gap: 10 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                <Text v="title" style={{ flex: 1 }}>
                    {unitName(item.vehicle)}
                </Text>
                <Chip label={stage.label} tone={stage.tone} />
            </View>
            <Text v="small">{[unitSub(item.vehicle), item.name].filter(Boolean).join(' · ')}</Text>
            {item.servicesLabel ? <Text color={c.muted}>{item.servicesLabel}</Text> : null}
            {children}
        </Card>
    );
}

/** The payment screenshot sits behind the sign-in, so the image request carries the bearer token */
function Proof({ url }: { url: string }) {
    const [token, setToken] = useState<string | null>(null);

    useEffect(() => {
        void getToken().then(setToken);
    }, []);

    // The server sends an absolute URL; on a demo copy in a folder it already includes it
    const uri = url.startsWith('http') ? url : `${API_URL}${url}`;

    return token ? <Image source={{ uri, headers: { Authorization: `Bearer ${token}` } }} accessibilityLabel="Payment screenshot" style={{ height: 200, borderRadius: 12, backgroundColor: c.surface2 }} contentFit="contain" /> : <Skeleton height={200} style={{ borderRadius: 12 }} />;
}

/** S6: the sales rep's day: drop-offs, units to size, payment screenshots, home requests */
export function TodayScreen() {
    const user = useUser();
    const [branch, setBranch] = useState<number | null>(user.branches[0]?.id ?? null);
    const { data, error, loading, reload } = useLoad(() => api.today(branch), `${branch}`);
    const sizes = useLoad(() => api.vehicleSizes());
    const [sizing, setSizing] = useState<UnitCard | null>(null);
    const [rejecting, setRejecting] = useState<UnitCard | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const money = canSeeMoney(user);

    const act = async (name: string, call: () => Promise<ApiResult<UnitDetail & { missingPrices?: string[] }>>, done: () => void) => {
        setBusy(name);
        setProblem(null);
        setNotice(null);
        const result = await call();
        setBusy(null);

        if (result.kind === 'ok') {
            done();
            if (result.data.missingPrices?.length) setNotice(`Size saved, but there is no price for this size yet: ${result.data.missingPrices.join(', ')}.`);
            await reload();
        } else {
            setProblem(problemText(result));
        }
    };

    const all = data ? data.dropOffsToday.length + data.needsSizeReview.length + data.paymentsToCheck.length + data.homeRequests.length : 0;

    return (
        <Screen tabs top={<TeamTop user={user} />} title="Today" subtitle={longDate(manilaToday())} onRefresh={reload}>
            <BranchBar branches={user.branches} value={branch} onChange={setBranch} />
            <FormError>{sizing || rejecting ? null : problem}</FormError>
            {notice ? <Text color={c.gold}>{notice}</Text> : null}
            {loading ? (
                <>
                    <Skeleton height={120} style={{ borderRadius: 16 }} />
                    <Skeleton height={120} style={{ borderRadius: 16 }} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    {all === 0 ? <EmptyState title="All clear" body="No drop-offs, sizes, payments or home requests waiting." /> : null}
                    {data.dropOffsToday.length ? (
                        <Section title="Drop-offs today" count={data.dropOffsToday.length}>
                            {data.dropOffsToday.map((item) => (
                                <Row key={item.id} item={item} />
                            ))}
                        </Section>
                    ) : null}
                    {data.needsSizeReview.length ? (
                        <Section title="Needs a size" count={data.needsSizeReview.length}>
                            {data.needsSizeReview.map((item) => (
                                <Row key={item.id} item={item}>
                                    <Button small icon={Ruler} label="Set size" accessibilityLabel={`Set size for ${unitName(item.vehicle)}`} onPress={() => { setProblem(null); setSizing(item); }} />
                                </Row>
                            ))}
                        </Section>
                    ) : null}
                    {data.paymentsToCheck.length ? (
                        <Section title="Payments to check" count={data.paymentsToCheck.length}>
                            {data.paymentsToCheck.map((item) => (
                                <Row key={item.id} item={item}>
                                    {item.payment.proofUrl ? <Proof url={item.payment.proofUrl} /> : <Text color={c.muted}>No screenshot yet.</Text>}
                                    {money && typeof item.payment.amountDue === 'number' ? (
                                        <Text weight="semi">
                                            {peso(item.payment.amountDue)}
                                            {item.payment.method ? ` · ${item.payment.method}` : ''}
                                        </Text>
                                    ) : null}
                                    <View style={{ flexDirection: 'row', gap: 10 }}>
                                        <Button style={{ flex: 1 }} icon={Check} label="Approve" accessibilityLabel={`Approve payment for ${unitName(item.vehicle)}`} loading={busy === `a${item.id}`} onPress={() => void act(`a${item.id}`, () => api.approvePayment(item.id), () => undefined)} />
                                        <Button style={{ flex: 1 }} kind="danger" icon={X} label="Reject" accessibilityLabel={`Reject payment for ${unitName(item.vehicle)}`} onPress={() => { setReason(''); setProblem(null); setRejecting(item); }} />
                                    </View>
                                </Row>
                            ))}
                        </Section>
                    ) : null}
                    {data.homeRequests.length ? (
                        <Section title="Home requests" count={data.homeRequests.length}>
                            {data.homeRequests.map((item) => (
                                <Row key={item.id} item={item}>
                                    {item.address ? <Text color={c.muted}>{[item.address, item.area].filter(Boolean).join(', ')}</Text> : null}
                                    {item.preferredDate ? (
                                        <Text v="small">
                                            {shortDate(item.preferredDate)}
                                            {item.preferredWindow ? ` · ${item.preferredWindow}` : ''}
                                        </Text>
                                    ) : null}
                                </Row>
                            ))}
                        </Section>
                    ) : null}
                </>
            ) : null}

            <Sheet open={!!sizing} onClose={() => setSizing(null)} title="Unit size">
                <Text color={c.muted}>{sizing ? unitName(sizing.vehicle) : ''}</Text>
                {sizes.loading ? <Skeleton height={48} /> : sizes.error ? <ErrorState result={sizes.error} onRetry={sizes.reload} /> : null}
                {(sizes.data ?? []).map((size: VehicleSize) => (
                    <Button key={size.id} kind="secondary" label={size.examples ? `${size.name} · ${size.examples}` : size.name} accessibilityLabel={`Size ${size.name}${size.examples ? `, ${size.examples}` : ''}`} loading={busy === `s${size.id}`} onPress={() => sizing && void act(`s${size.id}`, () => api.setSize(sizing.id, size.id), () => setSizing(null))} />
                ))}
                <FormError>{problem}</FormError>
            </Sheet>
            <Sheet open={!!rejecting} onClose={() => setRejecting(null)} title="Reject payment">
                <Field label="Reason" value={reason} onChangeText={setReason} multiline maxLength={255} hint="The customer sees this." />
                <FormError>{problem}</FormError>
                <Button label="Reject payment" kind="danger" icon={X} disabled={!reason.trim()} loading={busy === 'reject'} onPress={() => rejecting && void act('reject', () => api.rejectPayment(rejecting.id, reason.trim()), () => setRejecting(null))} />
            </Sheet>
        </Screen>
    );
}
