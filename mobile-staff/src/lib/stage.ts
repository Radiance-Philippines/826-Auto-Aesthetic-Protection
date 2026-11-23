import type { Card, Stage } from '@/api/types';
import type { Tone } from '@/theme';
import { ArrowRight, Check, LogOut, MapPinCheck, Wrench, type LucideIcon } from 'lucide-react-native';

/** Chip words and tones for a stage (design system DS3) */
export const STAGES: Record<Stage, { label: string; tone: Tone }> = {
    requested: { label: 'Requested', tone: 'gold' },
    awaiting_payment: { label: 'Awaiting payment', tone: 'gold' },
    payment_review: { label: 'Needs review', tone: 'gold' },
    confirmed: { label: 'Booked', tone: 'neutral' },
    arrived: { label: 'Arrived', tone: 'blue' },
    in_progress: { label: 'In progress', tone: 'teal' },
    quality_check: { label: 'Quality check', tone: 'gold' },
    ready: { label: 'Ready', tone: 'green' },
    exited: { label: 'Exited', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'neutral' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    declined: { label: 'Declined', tone: 'red' },
    no_show: { label: 'No show', tone: 'red' },
};

/** The stage a status text from the server maps to; anything unknown is shown as a neutral chip */
export const stageChip = (stage: string): { label: string; tone: Tone } => STAGES[stage as Stage] ?? { label: stage.replace(/_/g, ' '), tone: 'neutral' };

/** The icon on a stage button: the server words the label and says where it goes */
const ICONS: Record<string, LucideIcon> = { arrived: MapPinCheck, in_progress: Wrench, quality_check: ArrowRight, ready: Check, exited: LogOut };
export const stepIcon = (to: string): LucideIcon => ICONS[to] ?? ArrowRight;

/** The button a card shows: the server's next step, and only for a person who may move units */
export const buttonFor = (card: Pick<Card, 'next' | 'canAdvance'>) => (card.canAdvance ? card.next : null);

export type QueueFilter = 'all' | Stage;

/** "All 8", "Arrived 2", ...: a chip only for a stage that has units, All always */
export function queueFilters(cards: Pick<Card, 'stage'>[], order: Stage[]): { key: QueueFilter; label: string; count: number }[] {
    const counted = order.map((stage) => ({ key: stage as QueueFilter, label: STAGES[stage].label, count: cards.filter((card) => card.stage === stage).length })).filter((chip) => chip.count > 0);

    return [{ key: 'all', label: 'All', count: cards.length }, ...counted];
}

export const filterCards = <T extends Pick<Card, 'stage'>>(cards: T[], filter: QueueFilter): T[] => (filter === 'all' ? cards : cards.filter((card) => card.stage === filter));

/** The queue's columns, in order (the website Queue page) */
export const QUEUE_ORDER: Stage[] = ['confirmed', 'arrived', 'in_progress', 'quality_check', 'ready'];

/** "Day 1 of 3" from the server, "Done" once ready; null when it has no days */
export const dayLine = (card: Pick<Card, 'dayLabel' | 'stage'>): string | null => (card.stage === 'ready' ? 'Done' : card.dayLabel);
