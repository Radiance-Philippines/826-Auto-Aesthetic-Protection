import { Star } from 'lucide-react-native';
import { useState } from 'react';
import { View } from 'react-native';
import { api, type Period } from '../api';
import { useUser } from '../context/auth';
import { useLoad, longDate, manilaToday, peso, c, Card, Screen, Skeleton, ErrorState, Text } from '@826/core';
import { canSeeMoney } from '../lib/access';
import { initials } from '../lib/unit';
import { BranchBar } from '../ui/Branches';
import { TeamTop } from '../ui/Header';
import { Avatar, Pills } from '../ui/Pills';

const PERIODS: { key: Period; label: string }[] = [
    { key: 'today', label: 'Today' },
    { key: '7d', label: '7 days' },
    { key: '30d', label: '30 days' },
    { key: 'month', label: 'This month' },
];

const money = (value: number | null | undefined) => (typeof value === 'number' ? peso(value) : null);

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <Card style={{ flexGrow: 1, flexBasis: '45%', gap: 6 }}>
            <Text v="label">{label}</Text>
            <Text v="number" style={{ fontSize: 24 }}>
                {value}
            </Text>
        </Card>
    );
}

/** S7: the dashboard numbers for the person's branches. Only the cards the server returns. */
export function OverviewScreen() {
    const user = useUser();
    const [period, setPeriod] = useState<Period>('today');
    const [branch, setBranch] = useState<number | null>(null);
    const { data, error, loading, reload } = useLoad(() => api.overview(period, branch), `${period}|${branch}`);
    const showMoney = canSeeMoney(user);
    const attention = Object.entries({ 'late units': data?.attention.late, 'payment screenshots to check': data?.attention.screenshots, 'units to size': data?.attention.size, 'home requests': data?.attention.home, 'unread chats': data?.attention.chats, 'reviews to approve': data?.attention.reviews }).filter((entry): entry is [string, number] => typeof entry[1] === 'number' && entry[1] > 0);

    return (
        <Screen tabs top={<TeamTop user={user} />} title="Overview" subtitle={`${branch === null ? (user.branches.length > 1 ? 'All branches' : (user.branches[0]?.name ?? '')) : (user.branches.find((b) => b.id === branch)?.name ?? '')} · ${longDate(manilaToday())}`} onRefresh={reload}>
            <Pills label="Period" items={PERIODS} value={period} onChange={setPeriod} />
            <BranchBar all branches={user.branches} value={branch} onChange={setBranch} />
            {loading ? (
                <>
                    <Skeleton height={110} style={{ borderRadius: 16 }} />
                    <Skeleton height={160} style={{ borderRadius: 16 }} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 12 }}>
                        {money(data.sales) ? <Stat label="Sales" value={money(data.sales)!} /> : null}
                        {money(data.collected) ? <Stat label="Collected" value={money(data.collected)!} /> : null}
                        {typeof data.unitsDone === 'number' ? <Stat label="Units done" value={String(data.unitsDone)} /> : null}
                        {money(data.averageTicket) ? <Stat label="Average ticket" value={money(data.averageTicket)!} /> : null}
                    </View>
                    {data.branchesTable?.length ? (
                        <Card style={{ gap: 12 }}>
                            <Text v="title">Branches</Text>
                            {data.branchesTable.map((row) => (
                                <View key={row.name} style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                                    <Text weight="semi" style={{ flex: 1 }}>
                                        {row.name}
                                    </Text>
                                    <Text weight="bold">{peso(row.sales)}</Text>
                                    <Text v="small">
                                        {row.units} {row.units === 1 ? 'unit' : 'units'}
                                    </Text>
                                </View>
                            ))}
                        </Card>
                    ) : null}
                    {data.topReps?.length ? (
                        <Card style={{ gap: 12 }}>
                            <Text v="title">Top sales reps</Text>
                            {data.topReps.map((rep, index) => (
                                <View key={`${rep.name}${index}`} style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                                    <Avatar text={initials(rep.name)} size={40} />
                                    <Text weight="semi" style={{ flex: 1 }}>
                                        {index + 1}. {rep.name}
                                    </Text>
                                    {showMoney && typeof rep.sales === 'number' ? <Text weight="bold">{peso(rep.sales)}</Text> : typeof rep.jobs === 'number' ? <Text v="small">{rep.jobs} jobs</Text> : null}
                                </View>
                            ))}
                        </Card>
                    ) : null}
                    <View style={{ flexDirection: 'row', gap: 12 }}>
                        {typeof data.backjobsOpen === 'number' ? (
                            <Card style={{ flex: 1, gap: 6 }}>
                                <Text v="label">Backjobs open</Text>
                                <Text v="number" color={data.backjobsOpen > 0 ? c.danger : c.text} style={{ fontSize: 32, lineHeight: 38 }}>
                                    {data.backjobsOpen}
                                </Text>
                            </Card>
                        ) : null}
                        {data.reviewRating ? (
                            <Card style={{ flex: 1, gap: 6 }}>
                                <Text v="label">Reviews</Text>
                                {typeof data.reviewRating.rating === 'number' ? (
                                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                                        <Star size={20} color={c.gold} fill={c.gold} />
                                        <Text v="number">{data.reviewRating.rating.toFixed(1)}</Text>
                                    </View>
                                ) : (
                                    <Text color={c.muted}>No reviews yet</Text>
                                )}
                                <Text v="small">{data.reviewRating.count} approved</Text>
                            </Card>
                        ) : null}
                    </View>
                    {data.turnaround && (typeof data.turnaround.toReady === 'number' || typeof data.turnaround.toExit === 'number') ? (
                        <Card style={{ gap: 4 }}>
                            <Text v="label">Average turnaround</Text>
                            {typeof data.turnaround.toReady === 'number' ? <Text>{data.turnaround.toReady.toFixed(1)} days from arrival to ready</Text> : null}
                            {typeof data.turnaround.toExit === 'number' ? <Text>{data.turnaround.toExit.toFixed(1)} days from arrival to exit</Text> : null}
                        </Card>
                    ) : null}
                    {attention.length ? (
                        <Card style={{ gap: 6 }}>
                            <Text v="label">Needs attention</Text>
                            {attention.map(([label, count]) => (
                                <Text key={label}>
                                    {`${count} ${label}`}
                                </Text>
                            ))}
                        </Card>
                    ) : null}
                </>
            ) : null}
        </Screen>
    );
}
