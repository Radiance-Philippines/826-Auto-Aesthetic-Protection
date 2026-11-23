import { useRouter } from 'expo-router';
import { ChevronRight, Sparkles } from 'lucide-react-native';
import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { api, problemText, type Card as UnitCardData } from '../api';
import { useUser } from '../context/auth';
import { useLoad, manilaToday, c, Button, Card, Chip, Screen, Skeleton, EmptyState, ErrorState, FormError, Text } from '@826/core';
import { buttonFor, dayLine, filterCards, QUEUE_ORDER, queueFilters, STAGES, stepIcon, type QueueFilter } from '../lib/stage';
import { unitName, unitSub } from '../lib/unit';
import { BranchBar } from '../ui/Branches';
import { Confirm } from '../ui/Confirm';
import { TeamTop } from '../ui/Header';
import { Pills } from '../ui/Pills';

/** One unit in the queue, with the one button that moves it on */
export function UnitCard({ card, onOpen, onMove, busy }: { card: UnitCardData; onOpen: () => void; onMove: (card: UnitCardData) => void; busy?: boolean }) {
    const stage = STAGES[card.stage];
    const step = buttonFor(card);
    const days = dayLine(card);

    return (
        <Card style={{ gap: 12 }}>
            <Pressable accessibilityRole="button" accessibilityLabel={`${unitName(card.vehicle)}, ${stage.label}. Open`} onPress={onOpen} style={{ gap: 12 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                    <Chip label={stage.label} tone={stage.tone} />
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                        {card.late ? <Chip label="Late" tone="red" /> : null}
                        {card.needsSize ? <Chip label="Needs size" tone="gold" /> : null}
                        {days ? <Text v="small">{days}</Text> : null}
                    </View>
                </View>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                    <View style={{ flex: 1, gap: 2 }}>
                        <Text v="title">{unitName(card.vehicle)}</Text>
                        <Text v="small">{unitSub(card.vehicle)}</Text>
                    </View>
                    <ChevronRight size={20} color={c.muted} />
                </View>
                {card.services.length ? (
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                        <Sparkles size={16} color={c.muted} />
                        <Text color={c.muted} style={{ flex: 1 }}>
                            {card.services.join(', ')}
                        </Text>
                        {card.name ? <Text v="small">{card.name}</Text> : null}
                    </View>
                ) : null}
            </Pressable>
            {step ? <Button label={step.label} icon={stepIcon(step.to)} kind={card.stage === 'arrived' || card.stage === 'confirmed' ? 'secondary' : 'primary'} loading={busy} onPress={() => onMove(card)} /> : null}
        </Card>
    );
}

/** S2: today's queue */
export function QueueScreen() {
    const user = useUser();
    const router = useRouter();
    const [branch, setBranch] = useState<number | null>(user.branches[0]?.id ?? null);
    const [filter, setFilter] = useState<QueueFilter>('all');
    const [moving, setMoving] = useState<UnitCardData | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const date = manilaToday();
    const { data, error, loading, reload } = useLoad(() => api.queue(branch, date), `${branch}`);

    const move = async (card: UnitCardData) => {
        const step = buttonFor(card);
        if (!step) return;
        setBusy(card.id);
        setProblem(null);
        const result = await api.setStage(card.id, step.to);
        setBusy(null);
        setMoving(null);

        if (result.kind === 'ok') {
            await reload();
        } else {
            setProblem(problemText(result));
        }
    };

    const cards = data?.columns.flatMap((column) => column.cards) ?? [];
    const shown = filterCards(cards, filter);
    const filters = queueFilters(cards, QUEUE_ORDER);
    const activeFilter = filters.some((chip) => chip.key === filter) ? filter : 'all';

    return (
        <Screen
            tabs
            top={<TeamTop user={user} />}
            title="Today’s queue"
            subtitle={data ? `${data.heading} · ${cards.length} ${cards.length === 1 ? 'unit' : 'units'}` : undefined}
            onRefresh={reload}
        >
            <BranchBar
                branches={user.branches}
                value={branch}
                onChange={(id) => {
                    setBranch(id);
                    setFilter('all');
                }}
            />
            {data ? <Pills label="Filter by stage" items={filters.map((chip) => ({ key: chip.key, label: chip.label, count: chip.count }))} value={activeFilter} onChange={setFilter} /> : null}
            <FormError>{problem}</FormError>
            {loading ? (
                <>
                    <Skeleton height={150} style={{ borderRadius: 16 }} />
                    <Skeleton height={150} style={{ borderRadius: 16 }} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : shown.length === 0 ? (
                <EmptyState title="No units in the queue" body={cards.length ? 'Nothing at this stage right now.' : 'Nothing is booked or in the shop for this branch today.'} />
            ) : (
                shown.map((card) => <UnitCard key={card.id} card={card} busy={busy === card.id} onOpen={() => router.push(`/unit/${card.id}`)} onMove={(picked) => (buttonFor(picked)?.confirm ? setMoving(picked) : void move(picked))} />)
            )}
            <Confirm
                open={!!moving}
                title={`${moving?.next?.label ?? 'Confirm'}?`}
                body={moving ? `${unitName(moving.vehicle)}: ${moving.next?.label?.toLowerCase()}. This can’t be undone from the app.` : ''}
                action={moving?.next?.label ?? 'Confirm'}
                busy={!!moving && busy === moving.id}
                problem={problem}
                onConfirm={() => moving && void move(moving)}
                onClose={() => setMoving(null)}
            />
        </Screen>
    );
}
