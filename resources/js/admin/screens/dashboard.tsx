import { type ReactNode, useEffect, useId, useRef } from 'react';
import { useSearchParams } from 'react-router-dom';

import { apiBase } from '../lib/boot';
import { useResource } from '../lib/useResource';
import { ConsoleLink } from '../shell/ConsoleLink';
import { buttonClass } from '../ui/Button';
import { BarChart } from '../ui/Chart';
import { cx } from '../ui/cx';
import { DateField } from '../ui/DateField';
import { Icon, type IconName } from '../ui/icons';
import { PageHeader } from '../ui/PageHeader';
import { type Option, SelectField } from '../ui/Select';
import { EmptyState, ErrorState, Loading } from '../ui/states';

type Stat = { key?: string; label: string; value: string | number; help: string; tone?: string | null; url?: string | null };
type Block = { type?: 'stats'; title: string; help: string | null; wide?: boolean; items?: Stat[]; columns?: string[]; rows?: string[][]; empty?: string };
type Widget = { key: string; heading: string | null; description: string | null } & (
    | { type: 'stats'; stats: Stat[] }
    | { type: 'chart'; labels: string[]; sales: number[]; units: number; weekly: boolean; empty: string }
    | { type: 'blocks'; blocks: Block[] }
);
type Data = { filters: { periods: Option[]; branches: Option[]; today: string }; canExport: boolean; period: string; widgets: Widget[] };

const icons: Record<string, IconName> = {
    dropOffs: 'calendar',
    inShop: 'wrench',
    needsYou: 'inbox',
    size: 'truck',
    screenshots: 'image',
    home: 'house',
    reviews: 'star',
    chats: 'message-circle',
    late: 'clock',
    sales: 'banknote',
    collected: 'wallet',
    average: 'percent',
    units: 'badge-check',
    lost: 'circle-x',
    customers: 'users',
    discounts: 'tag',
    referrals: 'gift',
    redeemed: 'arrow-down',
    outstanding: 'calculator',
};

const tones: Record<string, string> = { warning: 'bg-warning text-warning-foreground', info: 'bg-info text-info-foreground', primary: 'bg-selected text-selected-foreground' };

const peso = (value: number) => `₱${value.toLocaleString('en-PH')}`;

function StatCard({ stat }: { stat: Stat }) {
    const card = 'flex h-full min-w-0 flex-col gap-1 rounded-xl border border-border bg-card p-4';
    const inside = (
        <>
            <span className="flex items-center justify-between gap-2 text-xs font-medium text-muted-foreground">
                {stat.label}
                {stat.key && icons[stat.key] && (
                    <span className={cx('flex size-7 shrink-0 items-center justify-center rounded-md', tones[stat.tone ?? ''] ?? 'bg-muted')}>
                        <Icon name={icons[stat.key]} />
                    </span>
                )}
            </span>
            <span className="text-2xl font-semibold break-words tabular-nums">{stat.value}</span>
            {stat.help && <span className="text-xs text-muted-foreground">{stat.help}</span>}
        </>
    );

    return stat.url ? (
        <ConsoleLink href={stat.url} className={cx(card, 'hover:border-primary')}>
            {inside}
        </ConsoleLink>
    ) : (
        <div className={card}>{inside}</div>
    );
}

const Stats = ({ stats }: { stats: Stat[] }) => (
    <ul className="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
        {stats.map((stat) => (
            <li key={stat.label} className="min-w-0">
                <StatCard stat={stat} />
            </li>
        ))}
    </ul>
);

function Table({ block }: { block: Block }) {
    if (!block.rows?.length) {
        return <p className="mt-2 text-sm text-muted-foreground">{block.empty ?? 'Nothing to show in this period.'}</p>;
    }

    return (
        // Scrolls sideways on a narrow screen: the region is focusable so the keyboard can too
        <div role="region" aria-label={`${block.title} table`} tabIndex={0} className="relative mt-3 overflow-x-auto rounded-lg border border-border bg-card">
            <table className="w-full min-w-max border-collapse text-sm">
                <caption className="sr-only">{block.title}</caption>
                <thead className="bg-muted/60">
                    <tr>
                        {block.columns!.map((column, index) => (
                            <th key={column} scope="col" className={cx('px-3 py-2 text-xs font-medium text-muted-foreground', index === 0 ? 'text-start' : 'text-end')}>
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {block.rows.map((row, at) => (
                        <tr key={`${row[0]}-${at}`}>
                            {row.map((cell, index) => (
                                <td key={block.columns![index]} className={cx('border-t border-border px-3 py-2', index === 0 ? 'text-start' : 'text-end tabular-nums')}>
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Card({ heading, description, children }: { heading: string | null; description: string | null; children: ReactNode }) {
    const id = useId();

    return (
        <section aria-labelledby={heading ? id : undefined} aria-label={heading ? undefined : 'Today'} className="min-w-0 space-y-3">
            {heading && (
                <div>
                    <h2 id={id} className="text-lg font-semibold">
                        {heading}
                    </h2>
                    {description && <p className="text-xs text-muted-foreground">{description}</p>}
                </div>
            )}
            {children}
        </section>
    );
}

function Body({ widget }: { widget: Widget }) {
    if (widget.type === 'stats') {
        return <Stats stats={widget.stats} />;
    }

    if (widget.type === 'chart') {
        return (
            <div className="rounded-xl border border-border bg-card p-4">
                {widget.units === 0 ? (
                    <p className="text-sm text-muted-foreground">{widget.empty}</p>
                ) : (
                    <BarChart label={widget.heading!} columnName={widget.weekly ? 'Week' : 'Day'} valueName="Sales" format={peso} points={widget.labels.map((label, index) => ({ label, value: widget.sales[index] }))} />
                )}
            </div>
        );
    }

    return (
        <div className="grid gap-5 rounded-xl border border-border bg-card p-4 lg:grid-cols-2">
            {widget.blocks.map((block) => (
                <div key={block.title} className={cx('min-w-0', block.wide && 'lg:col-span-2')}>
                    <h3 className="text-sm font-semibold">{block.title}</h3>
                    {block.help && <p className="mt-0.5 text-xs text-muted-foreground">{block.help}</p>}
                    {block.type === 'stats' ? (
                        <div className="mt-3">
                            <Stats stats={block.items!} />
                        </div>
                    ) : (
                        <Table block={block} />
                    )}
                </div>
            ))}
        </div>
    );
}

export default function Dashboard() {
    // The filters stay in the address
    const [params, setParams] = useSearchParams();
    const period = params.get('period') ?? 'last_30';
    const query = params.toString();
    const { data, error, loading, reload } = useResource<Data>(`/dashboard${query && `?${query}`}`);

    /** The minute's own look is quiet: only a change of filters dims the cards */
    const quiet = useRef(false);

    // What is waiting changes while the page is open: look again every minute it is in front
    useEffect(() => {
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                quiet.current = true;
                reload();
            }
        }, 60_000);

        return () => window.clearInterval(timer);
    }, [reload]);

    const choose = (name: string, value: string) => {
        quiet.current = false;
        setParams(
            (current) => {
                const next = new URLSearchParams(current);

                if (value) {
                    next.set(name, value);
                } else {
                    next.delete(name);
                }

                return next;
            },
            { replace: true },
        );
    };

    return (
        <>
            <PageHeader
                title="Dashboard"
                description={data?.period}
                actions={
                    data?.canExport && (
                        <a href={`${apiBase}/dashboard/export${query && `?${query}`}`} download className={buttonClass()}>
                            <Icon name="download" />
                            Export
                        </a>
                    )
                }
            />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={5} />
            ) : (
                <>
                    <div className="grid gap-3 rounded-xl border border-border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                        <SelectField label="Period" hint="The stretch the numbers cover." options={data.filters.periods} value={period} onChange={(value) => choose('period', value)} />
                        {period === 'custom' && (
                            <>
                                <DateField label="From" hint="First day, today at the latest." value={params.get('from')} max={data.filters.today} onChange={(value) => choose('from', value)} />
                                <DateField label="To" hint="Last day, today at the latest." value={params.get('to')} max={data.filters.today} onChange={(value) => choose('to', value)} />
                            </>
                        )}
                        {data.filters.branches.length > 0 && (
                            <SelectField
                                label="Branch"
                                hint="Narrows every number to one branch."
                                options={[{ value: 'all', label: 'All my branches' }, ...data.filters.branches]}
                                value={params.get('branch') ?? 'all'}
                                onChange={(value) => choose('branch', value === 'all' ? '' : value)}
                            />
                        )}
                    </div>

                    <div aria-busy={loading} className={cx('space-y-6', loading && !quiet.current && 'opacity-70')}>
                        {data.widgets.length === 0 && (
                            <EmptyState icon="layout-dashboard" title="Nothing to show here">
                                Your role has no dashboard cards in this panel.
                            </EmptyState>
                        )}
                        {data.widgets.map((widget) => (
                            <Card key={widget.key} heading={widget.heading} description={widget.description}>
                                <Body widget={widget} />
                            </Card>
                        ))}
                    </div>
                </>
            )}
        </>
    );
}
