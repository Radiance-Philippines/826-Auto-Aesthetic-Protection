import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@ui-sdk/react/collapsible';
import { useState } from 'react';
import { Link } from 'react-router-dom';

import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { type Column, DataTable } from '../../ui/DataTable';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { ColumnsMenu, useListParams } from '../bookings/parts';

type Row = { id: number; name: string; contact: string; branch: string | null; salesRep: string | null; units: number; bookings: number; lastVisit: string | null; referralCode: string | null };

type List = {
    data: Row[];
    meta: { total: number; perPage: number };
    filters: { branches: Option[]; salesReps: Option[]; sources: Option[] };
    can: { create: boolean };
};

const optional = [
    { id: 'branch', label: 'Branch' },
    { id: 'salesRep', label: 'Sales rep' },
    { id: 'referralCode', label: 'Referral code' },
];

export function CustomerList() {
    const { params, query, go, state, onStateChange } = useListParams();
    const { data, error, loading, reload } = useResource<List>(`/customers${query ? `?${query}` : ''}`);
    const [shown, setShown] = useState<Record<string, boolean>>({ branch: true, salesRep: true, referralCode: true });

    const columns: Column<Row>[] = [
        {
            id: 'name',
            header: 'Name',
            primary: true,
            sortValue: (row) => row.name,
            cell: (row) => (
                <>
                    <span className="block break-words">{row.name}</span>
                    <span className="block text-xs font-normal break-all text-muted-foreground">{row.contact}</span>
                </>
            ),
        },
        ...(shown.branch ? [{ id: 'branch', header: 'Branch', cell: (row: Row) => row.branch ?? '—' }] : []),
        ...(shown.salesRep ? [{ id: 'salesRep', header: 'Sales rep', cell: (row: Row) => row.salesRep ?? '—' }] : []),
        { id: 'units', header: 'Units', align: 'center', cell: (row) => <span className="tabular-nums">{row.units}</span> },
        { id: 'bookings', header: 'Bookings', align: 'center', cell: (row) => <span className="tabular-nums">{row.bookings}</span> },
        { id: 'lastVisit', header: 'Last visit', cell: (row) => row.lastVisit ?? '—' },
        ...(shown.referralCode ? [{ id: 'referralCode', header: 'Referral code', cell: (row: Row) => row.referralCode ?? '—' }] : []),
    ];

    const active = ['branch', 'salesRep', 'source'].filter((key) => params.get(key)).length;
    const addButton = data?.can.create && (
        <Link to="create" className={buttonClass('primary')}>
            <Icon name="plus" />
            Add a customer
        </Link>
    );

    const filter = (key: string, label: string, hint: string, options: Option[]) => (
        <SelectField label={label} hint={hint} options={[{ value: 'all', label: 'All' }, ...options]} value={params.get(key) ?? 'all'} onChange={(value) => go({ [key]: value === 'all' ? null : value })} />
    );

    return (
        <>
            <PageHeader title="Customers" actions={addButton} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={5} />
            ) : (
                <>
                    <Collapsible>
                        <div className="flex flex-wrap items-center gap-2">
                            <CollapsibleTrigger className={buttonClass('outline', 'sm')}>
                                <Icon name="sliders-horizontal" />
                                {active ? `Filters (${active})` : 'Filters'}
                            </CollapsibleTrigger>
                            <ColumnsMenu columns={optional} shown={shown} onChange={setShown} />
                        </div>
                        <CollapsiblePanel>
                            <div className="mt-2 grid items-start gap-4 rounded-xl border border-border bg-card p-4 sm:grid-cols-3">
                                {data.filters.branches.length > 0 && filter('branch', 'Branch', 'Their home branch.', data.filters.branches)}
                                {filter('salesRep', 'Sales rep', 'Who looks after them.', data.filters.salesReps)}
                                {filter('source', 'Source', 'How they came in.', data.filters.sources)}
                                <div className="sm:col-span-full">
                                    <Button icon="rotate-ccw" size="sm" onClick={() => go({ branch: null, salesRep: null, source: null })}>
                                        Reset
                                    </Button>
                                </div>
                            </div>
                        </CollapsiblePanel>
                    </Collapsible>

                    <div aria-busy={loading}>
                        <DataTable
                            caption="Customers"
                            columns={columns}
                            rows={data.data}
                            rowId={(row) => String(row.id)}
                            rowName={(row) => row.name}
                            state={state}
                            onStateChange={onStateChange}
                            total={data.meta.total}
                            pageSize={data.meta.perPage}
                            searchHint="By name, mobile, email or plate."
                            actions={(row) => (
                                <Link to={String(row.id)} className={buttonClass('outline', 'sm')} aria-label={`View ${row.name}`}>
                                    <Icon name="search" />
                                    View
                                </Link>
                            )}
                            empty={
                                <EmptyState icon="users" title="No customers" action={addButton || undefined}>
                                    {active ? 'No one matches the filters. Reset them to see more.' : 'Customers show up here once they book, or when you add them.'}
                                </EmptyState>
                            }
                        />
                    </div>
                </>
            )}
        </>
    );
}
