import { type FormEvent, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { addDays, isoDate, parseDate } from '../../lib/dates';
import { api, errorMessage, fieldErrors } from '../lib/api';
import { useLive } from '../lib/live';
import { useMe } from '../lib/me';
import { useResource } from '../lib/useResource';
import { Button, buttonClass } from '../ui/Button';
import { cx } from '../ui/cx';
import { DateField } from '../ui/DateField';
import { confirm, Modal, ModalActions } from '../ui/Dialog';
import { TextField } from '../ui/Field';
import { Icon, type IconName } from '../ui/icons';
import { PageHeader } from '../ui/PageHeader';
import { SelectField } from '../ui/Select';
import { Badge, EmptyState, ErrorState, Loading } from '../ui/states';
import { toast } from '../ui/toast';

type Card = {
    id: number;
    name: string;
    unit: string;
    assignedTo: string | null;
    canAssign: boolean;
    services: string;
    readyOn: string | null;
    home: boolean;
    needsSize: boolean;
    note: string | null;
    next: string | null;
    canAdvance: boolean;
    button: string | null;
    url: string;
};

type Board = {
    branch: { id: number; name: string } | null;
    branches: { id: number; name: string }[];
    date: string;
    heading: string;
    isToday: boolean;
    links: { newBooking: string | null; closures: string | null; sizeReviews: string; homeRequests: string };
    /* The rest is only there with a branch */
    dropOffs: { cap: number; units: number; state: 'ok' | 'full' | 'closed' };
    sizeReviews: number;
    homeRequests: number;
    columns: { key: string; label: string; cards: Card[] }[];
    arrivals: Card[];
};

/** The icon of the button that moves a card to this status */
const stepIcons: Record<string, IconName> = { arrived: 'arrow-right-to-line', in_progress: 'wrench', quality_check: 'search-check', ready: 'badge-check', exited: 'circle-check-big' };

function Strip({ board }: { board: Board }) {
    const { cap, units, state } = board.dropOffs;

    return (
        <div
            role="status"
            className={cx(
                'flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-3 text-sm',
                state === 'full' ? 'border-destructive/40 bg-destructive/10 text-destructive' : state === 'closed' ? 'border-border bg-muted' : 'border-border bg-card',
            )}
        >
            {cap < 1 ? (
                <p>This branch has no daily cap set, so it takes no online bookings. Set it in Shop → Branches.</p>
            ) : (
                <>
                    <p className="font-semibold">
                        Dropping off {board.isToday ? 'today' : `on ${board.heading}`}: {units} of {cap}
                    </p>
                    <p>{state === 'closed' ? 'Closed day' : state === 'full' ? (units > cap ? 'Over the cap' : 'Full') : `${cap - units} ${cap - units === 1 ? 'place' : 'places'} left`}</p>
                </>
            )}
        </div>
    );
}

function Unit({ card, onAdvance, onAssign }: { card: Card; onAdvance: (card: Card) => void; onAssign: (card: Card) => void }) {
    return (
        <article data-card={card.id} aria-label={`${card.unit}, ${card.name}`} tabIndex={-1} className="rounded-lg border border-border bg-card p-2.5 text-sm shadow-xs">
            <a href={card.url} className="block rounded-sm hover:underline">
                <span className="block font-semibold break-words">{card.unit}</span>
                <span className="block break-words">{card.name}</span>
                <span className="mt-1 block text-xs text-muted-foreground">{card.services}</span>
                {card.readyOn && <span className="mt-0.5 block text-xs text-muted-foreground">Ready around {card.readyOn}</span>}
            </a>

            {card.assignedTo && <p className="mt-1.5 text-xs font-medium break-words">Assigned to {card.assignedTo}</p>}

            {(card.needsSize || card.note || card.home) && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {card.needsSize && <Badge tone="warning">Size needed</Badge>}
                    {card.note && <Badge tone="info">{card.note}</Badge>}
                    {card.home && <Badge>Home visit</Badge>}
                </div>
            )}

            {(card.canAdvance || card.canAssign) && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {card.canAdvance && (
                        <Button icon={stepIcons[card.next!]} size="sm" onClick={() => onAdvance(card)}>
                            {card.button}
                        </Button>
                    )}
                    {card.canAssign && (
                        <Button icon="user" size="sm" aria-label={`${card.assignedTo ? 'Change who is on' : 'Assign someone to'} ${card.unit}`} onClick={() => onAssign(card)}>
                            {card.assignedTo ? 'Change' : 'Assign'}
                        </Button>
                    )}
                </div>
            )}
        </article>
    );
}

/** "Assigned to": the name or names as typed; blank clears it */
function AssignForm({ card, onDone, onCancel }: { card: Card; onDone: () => void; onCancel: () => void }) {
    const [value, setValue] = useState(card.assignedTo ?? '');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await api('POST', `/queue/${card.id}/assigned-to`, { assignedTo: value });
            toast.success('Saved');
            onDone();
        } catch (failure) {
            setError(fieldErrors(failure).assignedTo ?? errorMessage(failure));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={save} noValidate>
            <TextField label="Assigned to" hint="Type the name or names. Leave blank to clear." error={error} value={value} onValueChange={setValue} placeholder="Jun, Ben" maxLength={120} autoComplete="off" />
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save
                </Button>
            </ModalActions>
        </form>
    );
}

export default function Queue() {
    const { me } = useMe();
    const [params, setParams] = useSearchParams();
    const search = params.toString();
    const { data: board, error, loading, reload } = useResource<Board>(`/queue${search ? `?${search}` : ''}`);
    const [assigning, setAssigning] = useState<Card | null>(null);
    // The card to put the keyboard back on once it has moved to its new column
    const moved = useRef<number | null>(null);

    useLive(me.live, 'queue', board?.branch?.id ?? null, reload);

    useEffect(() => {
        if (moved.current !== null && !loading) {
            (document.querySelector<HTMLElement>(`[data-card="${moved.current}"]`) ?? document.getElementById('main'))?.focus();
            moved.current = null;
        }
    }, [board, loading]);

    const go = (changes: Record<string, string | null>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);

                for (const [key, value] of Object.entries(changes)) {
                    if (value === null) {
                        next.delete(key);
                    } else {
                        next.set(key, value);
                    }
                }

                return next;
            },
            { replace: true },
        );

    const advance = async (card: Card) => {
        const yes = await confirm({ title: `${card.button}?`, description: `${card.name}, ${card.unit}`, label: card.button!, icon: stepIcons[card.next!] });

        if (!yes) {
            return;
        }

        try {
            const unit = await api<{ statusLabel: string }>('POST', `/queue/${card.id}/stage`, { to: card.next });
            toast.success(`${card.name}: ${unit.statusLabel}`);
            moved.current = card.id;
        } catch (failure) {
            // Someone else may have moved it: the board is asked again either way
            toast.error(errorMessage(failure));
        }

        reload();
    };

    const header = (
        <PageHeader
            title="Queue"
            actions={
                <>
                    {board?.links.newBooking && (
                        <a href={board.links.newBooking} className={buttonClass('primary')}>
                            <Icon name="plus" />
                            Add a booking
                        </a>
                    )}
                    {board?.links.closures && (
                        <a href={board.links.closures} className={buttonClass()}>
                            <Icon name="ban" />
                            Closures and full days
                        </a>
                    )}
                </>
            }
        />
    );

    if (!board) {
        return (
            <>
                {header}
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={4} />}
            </>
        );
    }

    if (!board.branch) {
        return (
            <>
                {header}
                <EmptyState icon="store" title="No branch yet">
                    You’re not assigned to a branch yet. Ask an owner to add you to one in Settings → Users.
                </EmptyState>
            </>
        );
    }

    // The day asked for, so two quick presses move two days
    const date = params.get('date') ?? board.date;
    const step = (days: number) => go({ date: isoDate(addDays(parseDate(date), days)) });

    return (
        <>
            {header}
            {error && <ErrorState message={error} onRetry={reload} />}

            <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
                {board.branches.length > 1 ? (
                    <SelectField
                        label="Branch"
                        hint="Whose queue to show."
                        hideLabel
                        className="w-full sm:w-56"
                        options={board.branches.map((branch) => ({ value: String(branch.id), label: branch.name }))}
                        value={String(board.branch.id)}
                        onChange={(branch) => go({ branch })}
                    />
                ) : (
                    <p className="flex h-9 items-center font-medium">{board.branch.name}</p>
                )}

                <div className="flex flex-wrap items-center gap-1">
                    <Button icon="chevron-left" aria-label="Earlier" variant="ghost" onClick={() => step(-1)} />
                    <h2 aria-live="polite" className="min-w-44 text-center text-base font-semibold tabular-nums">
                        {board.heading}
                    </h2>
                    <Button icon="chevron-right" aria-label="Later" variant="ghost" onClick={() => step(1)} />
                    <Button icon="calendar" onClick={() => go({ date: null })}>
                        Today
                    </Button>
                </div>

                <DateField label="Jump to a day" hint="Type a date, or open the calendar." hideLabel className="w-full sm:w-52" value={board.date} onChange={(next) => go({ date: next })} />
            </div>

            <Strip board={board} />

            {(board.sizeReviews > 0 || board.homeRequests > 0) && (
                <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    {board.sizeReviews > 0 && (
                        <a href={board.links.sizeReviews} className="underline underline-offset-4">
                            {board.sizeReviews} {board.sizeReviews === 1 ? 'unit needs' : 'units need'} a size review
                        </a>
                    )}
                    {board.homeRequests > 0 && (
                        <a href={board.links.homeRequests} className="underline underline-offset-4">
                            {board.homeRequests} home {board.homeRequests === 1 ? 'request' : 'requests'} to schedule
                        </a>
                    )}
                </p>
            )}

            {/* Five columns where they fit, fewer on a tablet, stacked on a phone */}
            <div aria-busy={loading} className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,13rem),1fr))] items-start gap-3">
                {board.columns.map((column) => (
                    <section key={column.key} aria-labelledby={`queue-${column.key}`} className="min-w-0 rounded-xl bg-muted p-2">
                        <h2 id={`queue-${column.key}`} className="flex items-center justify-between px-1 py-1 text-sm font-semibold">
                            {column.label}
                            <span className="rounded-full bg-card px-2 text-xs font-medium tabular-nums">{column.cards.length}</span>
                        </h2>
                        <div className="space-y-2">
                            {column.cards.length === 0 && <p className="px-1 py-2 text-xs text-muted-foreground">Nothing here.</p>}
                            {column.cards.map((card) => (
                                <Unit key={card.id} card={card} onAdvance={advance} onAssign={setAssigning} />
                            ))}
                        </div>
                    </section>
                ))}
            </div>

            <section aria-labelledby="queue-arrivals" className="rounded-xl border border-border bg-card p-4">
                <h2 id="queue-arrivals" className="text-sm font-semibold">
                    Expected today
                </h2>
                {board.arrivals.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">No booked units still to arrive today.</p>
                ) : (
                    <ul className="m-0 mt-2 list-none divide-y divide-border p-0">
                        {board.arrivals.map((card) => (
                            <li key={card.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <a href={card.url} className="min-w-0 rounded-sm hover:underline">
                                    <span className="block truncate text-sm font-medium">
                                        {card.name} · {card.unit}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">{card.services}</span>
                                </a>
                                {card.canAdvance && (
                                    <Button icon="arrow-right-to-line" size="sm" onClick={() => advance(card)}>
                                        Mark arrived
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <Modal open={assigning !== null} onClose={() => setAssigning(null)} title="Who is on this unit?" description={assigning && `${assigning.name}, ${assigning.unit}`}>
                {assigning && (
                    <AssignForm
                        key={assigning.id}
                        card={assigning}
                        onCancel={() => setAssigning(null)}
                        onDone={() => {
                            setAssigning(null);
                            reload();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
