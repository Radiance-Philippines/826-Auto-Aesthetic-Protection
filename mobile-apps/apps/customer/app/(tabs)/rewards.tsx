import { Check, Copy, Share2, Star, Users } from 'lucide-react-native';
import { View } from 'react-native';
import { api, type Rewards } from '@/api';
import { useCopied } from '@/hooks/useCopied';
import { useLoad, dayMonthYear, radius, useTheme, Button, Card, Chip, CountUp, Enter, Screen, Skeleton, ErrorState, Text } from '@826/core';
import { shareReferral } from '@/lib/share';

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

const STEPS = ['Share your code with a friend', 'Your friend books with it', 'They get a discount, you get points'];

export default function RewardsScreen() {
    const c = useTheme();
    const { data, error, loading, reload } = useLoad<Rewards>(() => api.rewards());
    const [copied, copy] = useCopied();

    return (
        <Screen brand title="Rewards" onRefresh={reload} tabs>
            {loading ? (
                <>
                    <Skeleton height={230} />
                    <Skeleton height={140} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data && (!data.enabled || !data.code) ? (
                <Card style={{ gap: 8 }}>
                    <Text v="title">Referral rewards aren’t on right now</Text>
                    <Text color={c.muted}>When 826 turns them on, your code and points show here.</Text>
                </Card>
            ) : data ? (
                <>
                    <Enter index={0}>
                        <Card style={{ gap: 14, padding: 20, backgroundColor: c.goldPanel, borderColor: c.goldLine }}>
                            <Text v="label" style={{ textAlign: 'center' }}>
                                Your referral code
                            </Text>
                            <Text v="display" accessibilityLabel={`Your code ${data.code}`} style={{ textAlign: 'center', fontSize: 30, lineHeight: 38 }} adjustsFontSizeToFit numberOfLines={1}>
                                {data.code}
                            </Text>
                            <Text color={c.muted} style={{ textAlign: 'center', fontSize: 14 }}>
                                {data.discountLabel ? `Friends who book with this code get ${data.discountLabel} on their first booking. You earn points for each one.` : 'Friends who book with this code get a discount on their first booking. You earn points for each one.'}
                            </Text>
                            <View accessible accessibilityLabel={`Your points: ${data.pointsBalance}`} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.bg, borderRadius: radius.md, padding: 14 }}>
                                <Star size={20} color={c.gold} />
                                <Text color={c.muted} style={{ flex: 1 }}>
                                    Your points
                                </Text>
                                <CountUp v="number" color={c.gold} value={data.pointsBalance} />
                            </View>
                            <View style={{ flexDirection: 'row', gap: 10 }}>
                                <Button kind="secondary" icon={copied ? Check : Copy} label={copied ? 'Copied' : 'Copy code'} onPress={() => void copy(data.code as string)} style={{ flex: 1 }} />
                                <Button icon={Share2} label="Share" onPress={() => void shareReferral(data.code as string, data.discountLabel)} style={{ flex: 1 }} />
                            </View>
                        </Card>
                    </Enter>

                    <Enter index={1}>
                        <Card style={{ gap: 12 }}>
                            <Text v="title">How it works</Text>
                            {STEPS.map((step, index) => (
                                <View key={step} style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                                    <View style={{ width: 28, height: 28, borderRadius: 14, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' }}>
                                        <Text weight="bold" color={c.gold} style={{ fontSize: 13 }}>
                                            {index + 1}
                                        </Text>
                                    </View>
                                    <Text style={{ flex: 1 }}>{step}</Text>
                                </View>
                            ))}
                        </Card>
                    </Enter>

                    <Enter index={2}>
                        <Card style={{ gap: 10 }}>
                            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                                <Text v="title">{data.friends.length === 1 ? '1 friend used your code' : data.friends.length ? `${data.friends.length} friends used your code` : 'No friends yet'}</Text>
                                <Users size={18} color={c.steel} />
                            </View>
                            {data.friends.length === 0 ? <Text color={c.muted}>When a friend books with your code, they show here.</Text> : null}
                            {data.friends.map((friend, index) => (
                                <View key={`${friend.name}-${friend.usedOn}-${index}`} style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingTop: index ? 10 : 0, borderTopWidth: index ? 1 : 0, borderTopColor: c.line }}>
                                    <View style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' }}>
                                        <Text weight="bold" style={{ fontSize: 12 }} color={c.muted}>
                                            {initials(friend.name)}
                                        </Text>
                                    </View>
                                    <View style={{ flex: 1 }}>
                                        <Text weight="semi">{friend.name}</Text>
                                        <Text v="small">{dayMonthYear(friend.usedOn)}</Text>
                                    </View>
                                    {friend.status === 'awarded' ? (
                                        <Text weight="bold" color={c.gold}>{`+${friend.points} pts`}</Text>
                                    ) : (
                                        <Chip label={friend.status === 'pending' ? 'On the way' : 'Not counted'} tone={friend.status === 'pending' ? 'gold' : 'neutral'} />
                                    )}
                                </View>
                            ))}
                        </Card>
                    </Enter>

                    <Enter index={3}>
                        <Card style={{ gap: 10 }}>
                            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                                <Text v="title">Points history</Text>
                                <Text v="small">{`${data.pointsEarned} earned · ${data.pointsSpent} used`}</Text>
                            </View>
                            {data.ledger.length === 0 ? <Text color={c.muted}>Nothing yet.</Text> : null}
                            {data.ledger.map((row, index) => (
                                <View key={`${row.date}-${index}`} style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
                                    <View style={{ flex: 1 }}>
                                        <Text>{row.note ?? (row.kind === 'earned' ? 'Points earned' : row.kind === 'spent' ? 'Points used' : 'Adjusted')}</Text>
                                        <Text v="small">{dayMonthYear(row.date)}</Text>
                                    </View>
                                    <Text weight="bold" color={row.points >= 0 ? c.gold : c.danger}>
                                        {row.points >= 0 ? `+${row.points}` : row.points}
                                    </Text>
                                </View>
                            ))}
                        </Card>
                    </Enter>
                </>
            ) : null}
        </Screen>
    );
}
