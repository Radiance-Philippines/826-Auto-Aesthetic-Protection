import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@ui-sdk/react/collapsible';
import { useState } from 'react';
import { Link } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useLive } from '../../lib/live';
import { useMe } from '../../lib/me';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { cx } from '../../ui/cx';
import { type Column, DataTable } from '../../ui/DataTable';
import { confirm } from '../../ui/Dialog';
import { Icon, type IconName } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { TabBar } from '../../ui/Tabs';
import { toast } from '../../ui/toast';
import { CheckboxListField, ChoiceField, ColumnsMenu, Tick, Tone, useListParams } from './parts';

type Row = {
    id: number;
    reference: string;
    unread: boolean;
    dropOff: string;
    dropOffNote: string;
    name: string;
    phone: string;
    phoneUrl: string | null;
    customerId: number | null;
    services: string;
    vehicle: string;
    branch: string | null;
    status: string;
    tone: string;
    statusNote: string | null;
    price: string;
    needsSize: boolean;
    made: string;
};

type List = {
    data: Row[];
    meta: { total: number; perPage: number };
    tab: string;
    tabs: { key: string; label: string; badge: number }[];
    filters: { branches: Option[]; kinds: Option[]; statuses: Option[] };
    can: { create: boolean; delete: boolean };
};

const tabIcons: Record<string, IconName> = { action: 'circle-alert', size: 'truck', today: 'calendar', in_shop: 'wrench', upcoming: 'arrow-right-to-line', holds: 'wallet', home: 'house', past: 'circle-check-big', cancelled: 'ban', all: 'layout-grid' };

/** Columns the person may switch off, as in the Filament table ("Made" starts off) */
const optional = [
    { id: 'branch', label: 'Branch', on: true },
    { id: 'price', label: 'Price', on: true },
    { id: 'made', label: 'Made', on: false },
];

export function BookingList() {
    const { me, reload: reloadMe } = useMe();
    const { params, query, go, state, onStateChange } = useListParams();
    const { data, error, loading, reload } = useResource<List>(`/bookings${query ? `?${query}` : ''}`);
    const [shown, setShown] = useState(() => Object.fromEntries(optional.map((column) => [column.id, column.on])));
    const [picked, setPicked] = useState<{ query: string; ids: number[] }>({ query: '', ids: [] });
    // A new tab, filter, search or page starts with nothing ticked
    const selected = picked.query === query ? picked.ids : [];
    const select = (ids: number[]) => setPicked({ query, ids });

    useLive(me.live, 'bookings', null, reload);

    const mark = async (read: boolean) => {
        try {
            await api('POST', '/bookings/mark', { ids: selected, read });
            toast.success(read ? 'Marked as read' : 'Marked as unread');
            select([]);
            reload();
            reloadMe();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    const remove = async () => {
        const yes = await confirm({ title: 'Delete selected bookings', description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true });

        if (!yes) {
            return;
        }

        try {
            await api('POST', '/bookings/delete', { ids: selected });
            toast.success('Deleted');
            select([]);
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        reload();
        reloadMe();
    };

    const rows = data?.data ?? [];

    const columns: Column<Row>[] = [
        {
            id: 'select',
            header: 'Select',
            cell: (row) => <Tick label={`Select ${row.name} ${row.reference}`} checked={selected.includes(row.id)} onChange={(on) => select(on ? [...selected, row.id] : selected.filter((id) => id !== row.id))} />,
        },
        {
            id: 'read',
            header: 'Read',
            align: 'center',
            cell: (row) => (
                <span title={row.unread ? 'Unread' : 'Read'}>
                    <Icon name={row.unread ? 'circle' : 'circle-check'} className={cx('inline size-4', row.unread ? 'fill-primary text-primary' : 'text-muted-foreground')} />
                    <span className="sr-only">{row.unread ? 'Unread' : 'Read'}</span>
                </span>
            ),
        },
        {
            id: 'dropOff',
            header: 'Drop-off',
            sortValue: (row) => row.dropOff,
            cell: (row) => (
                <span className={cx(row.unread && 'font-semibold')}>
                    {row.dropOff}
                    <span className="block text-xs font-normal text-muted-foreground">{row.dropOffNote}</span>
                </span>
            ),
        },
        {
            id: 'name',
            header: 'Customer',
            primary: true,
            cell: (row) => (
                <span className={cx(row.unread ? 'font-semibold' : 'font-normal')}>
                    {row.customerId ? (
                        <Link to={`/customers/${row.customerId}`} className="break-words underline-offset-4 hover:underline">
                            {row.name}
                        </Link>
                    ) : (
                        <span className="break-words">{row.name}</span>
                    )}
                    <span className="block text-xs font-normal break-words text-muted-foreground">
                        {row.reference} · {row.phone}
                    </span>
                </span>
            ),
        },
        {
            id: 'services',
            header: 'Services',
            cell: (row) => (
                <>
                    <span className="break-words">{row.services}</span>
                    <span className="block text-xs break-words text-muted-foreground">{row.vehicle}</span>
                </>
            ),
        },
        ...(shown.branch ? [{ id: 'branch', header: 'Branch', cell: (row: Row) => row.branch }] : []),
        {
            id: 'status',
            header: 'Status',
            cell: (row) => (
                <>
                    <Tone tone={row.tone}>{row.status}</Tone>
                    {row.statusNote && <span className="block text-xs text-muted-foreground">{row.statusNote}</span>}
                </>
            ),
        },
        ...(shown.price ? [{ id: 'price', header: 'Price', cell: (row: Row) => (row.needsSize ? <Tone tone="warning">{row.price}</Tone> : <span className="tabular-nums">{row.price}</span>) }] : []),
        ...(shown.made ? [{ id: 'made', header: 'Made', sortValue: (row: Row) => row.made, cell: (row: Row) => row.made }] : []),
    ];

    const status = params.getAll('status[]');
    const active = [params.get('branch'), params.get('kind'), status.length > 0].filter(Boolean).length;
    const addButton = data?.can.create && (
        <Link to="create" className={buttonClass('primary')}>
            <Icon name="plus" />
            Add a booking
        </Link>
    );

    return (
        <>
            <PageHeader title="Bookings" actions={addButton} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={5} />
            ) : (
                <TabBar
                    label="Bookings by stage"
                    value={params.get('tab') ?? data.tab}
                    onChange={(tab) => go({ tab })}
                    tabs={data.tabs.map((tab) => ({ value: tab.key, label: tab.label, icon: tabIcons[tab.key] ?? 'circle', badge: tab.badge }))}
                >
                    <Collapsible>
                        <div className="flex flex-wrap items-center gap-2">
                            <CollapsibleTrigger className={buttonClass('outline', 'sm')}>
                                <Icon name="sliders-horizontal" />
                                {active ? `Filters (${active})` : 'Filters'}
                            </CollapsibleTrigger>
                            <ColumnsMenu columns={optional} shown={shown} onChange={setShown} />
                            {rows.length > 0 && (
                                <label className="ms-auto flex cursor-pointer items-center gap-2 text-sm pointer-coarse:min-h-11">
                                    <Tick
                                        label="Select all on this page"
                                        checked={selected.length === rows.length}
                                        indeterminate={selected.length > 0 && selected.length < rows.length}
                                        onChange={(on) => select(on ? rows.map((row) => row.id) : [])}
                                    />
                                    <span aria-hidden="true">Select all on this page</span>
                                </label>
                            )}
                        </div>
                        <CollapsiblePanel>
                            <div className="mt-2 grid items-start gap-4 rounded-xl border border-border bg-card p-4 md:grid-cols-[14rem_16rem_minmax(0,1fr)]">
                                {data.filters.branches.length > 0 && (
                                    <SelectField label="Branch" hint="Only that branch’s bookings." options={[{ value: 'all', label: 'All' }, ...data.filters.branches]} value={params.get('branch') ?? 'all'} onChange={(branch) => go({ branch: branch === 'all' ? null : branch })} />
                                )}
                                <ChoiceField label="Kind" hint="At the branch, or at the customer’s." options={[{ value: 'any', label: 'Any', icon: 'layout-grid' as const }, ...data.filters.kinds.map((kind) => ({ ...kind, icon: kind.value === 'home' ? ('house' as const) : ('store' as const) }))]} value={params.get('kind') ?? 'any'} onChange={(kind) => go({ kind: kind === 'any' ? null : kind })} />
                                <CheckboxListField label="Status" hint="Tick one or more. None ticked shows every status." options={data.filters.statuses} value={status} onChange={(next) => go({ 'status[]': next })} columns />
                                <div className="md:col-span-full">
                                    <Button icon="rotate-ccw" size="sm" onClick={() => go({ branch: null, kind: null, 'status[]': null })}>
                                        Reset
                                    </Button>
                                </div>
                            </div>
                        </CollapsiblePanel>
                    </Collapsible>

                    {selected.length > 0 && (
                        <div role="region" aria-label="Bulk actions" className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-selected px-3 py-2 text-sm text-selected-foreground">
                            <p className="me-auto font-medium">{selected.length} selected</p>
                            <Button icon="circle-check" size="sm" onClick={() => mark(true)}>
                                Mark as read
                            </Button>
                            <Button icon="circle" size="sm" onClick={() => mark(false)}>
                                Mark as unread
                            </Button>
                            {data.can.delete && (
                                <Button icon="trash-2" size="sm" variant="destructive" onClick={remove}>
                                    Delete selected
                                </Button>
                            )}
                        </div>
                    )}

                    <div aria-busy={loading}>
                        <DataTable
                            caption="Bookings"
                            columns={columns}
                            rows={rows}
                            rowId={(row) => String(row.id)}
                            rowName={(row) => `${row.name} ${row.reference}`}
                            state={state}
                            onStateChange={onStateChange}
                            total={data.meta.total}
                            pageSize={data.meta.perPage}
                            searchHint="By name, mobile, reference or plate."
                            actions={(row) => (
                                <>
                                    <Link to={String(row.id)} className={buttonClass('outline', 'sm')} aria-label={`View ${row.name} ${row.reference}`}>
                                        <Icon name="search" />
                                        View
                                    </Link>
                                    {row.phoneUrl && (
                                        <a href={row.phoneUrl} className={buttonClass('ghost', 'sm')} aria-label={`Call ${row.name}`}>
                                            <Icon name="smartphone" />
                                            Call
                                        </a>
                                    )}
                                </>
                            )}
                            empty={
                                <EmptyState icon="inbox" title="No bookings" action={addButton || undefined}>
                                    {active ? 'Nothing here matches the filters. Reset them to see more.' : 'Nothing in this tab right now.'}
                                </EmptyState>
                            }
                        />
                    </div>
                </TabBar>
            )}
        </>
    );
}
