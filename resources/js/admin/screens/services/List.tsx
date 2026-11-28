import { useState } from 'react';
import { Link } from 'react-router-dom';

import { useResource } from '../../lib/useResource';
import { buttonClass } from '../../ui/Button';
import { type Column, DataTable, useClientTable } from '../../ui/DataTable';
import { Icon } from '../../ui/icons';
import { ActionMenu } from '../../ui/Menu';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { useReorder } from '../branches/useReorder';
import { YesNo } from '../branches/YesNo';

type Row = {
    id: number;
    name: string;
    categoryId: number;
    category: string | null;
    estimateDays: number | null;
    takes: string;
    prices: string;
    priced: number;
    sizes: number;
    branches: number;
    homeServiceOk: boolean;
    isActive: boolean;
};

const columns: Column<Row>[] = [
    {
        id: 'name',
        header: 'Name',
        primary: true,
        sortValue: (service) => service.name,
        cell: (service) => (
            <>
                <span className="block break-words">{service.name}</span>
                {service.category && <span className="block text-xs font-normal text-muted-foreground">{service.category}</span>}
            </>
        ),
    },
    { id: 'takes', header: 'Takes', cell: (service) => <span className={service.estimateDays ? '' : 'rounded-md bg-warning px-1.5 text-warning-foreground'}>{service.takes}</span> },
    {
        id: 'prices',
        header: 'Prices',
        cell: (service) => (
            <span className="inline-block">
                <span className={service.priced ? 'tabular-nums' : 'rounded-md bg-warning px-1.5 text-warning-foreground'}>{service.prices}</span>
                <span className="block text-xs text-muted-foreground">
                    {service.priced} of {service.sizes} size classes
                </span>
            </span>
        ),
    },
    { id: 'branches', header: 'Branches', align: 'center', cell: (service) => <span className="tabular-nums">{service.branches}</span> },
    { id: 'home', header: 'At home', align: 'center', cell: (service) => <YesNo yes={service.homeServiceOk} /> },
    { id: 'active', header: 'Bookable', align: 'center', cell: (service) => <YesNo yes={service.isActive} /> },
];

export function ServiceList() {
    const { data, error, reload } = useResource<{ data: Row[]; categories: Option[] }>('/services');
    const [category, setCategory] = useState('all');
    const order = useReorder(data?.data ?? [], '/services', reload, (service) => service.name);
    const shown = category === 'all' ? order.rows : order.rows.filter((service) => String(service.categoryId) === category);
    const table = useClientTable(shown, columns, { searchText: (service) => service.name, pageSize: 50 });
    // Rows move only in the whole list's own order
    const movable = category === 'all' && !table.state.search && !table.state.sort;

    const add = (
        <Link to="/services/create" className={buttonClass('primary')}>
            <Icon name="plus" />
            Add a service
        </Link>
    );

    return (
        <>
            <PageHeader title="Services" description="What customers can book: one estimate in days, and a price for each size class." actions={add} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={6} />
            ) : (
                <>
                    <SelectField
                        label="Category"
                        hint={movable ? 'Show one category. Rows move while every category is shown.' : 'Show every category and clear the search and sorting to move rows.'}
                        options={[{ value: 'all', label: 'All categories' }, ...data.categories]}
                        value={category}
                        onChange={(value) => {
                            setCategory(value);
                            table.onStateChange({ ...table.state, page: 1 });
                        }}
                        className="max-w-sm"
                    />
                    <DataTable
                        {...table}
                        caption="Services"
                        columns={columns}
                        rowId={(service) => String(service.id)}
                        rowName={(service) => service.name}
                        searchHint="By service name."
                        onReorder={category === 'all' ? (ids) => order.reorderPage(ids, table.state.page, table.pageSize) : undefined}
                        actions={(service) => (
                            <>
                                <Link to={`/services/${service.id}/edit`} className={buttonClass('outline', 'sm')} aria-label={`Edit ${service.name}`}>
                                    <Icon name="pencil" />
                                    Edit
                                </Link>
                                <ActionMenu label={`More actions for ${service.name}`} actions={order.moves(service, movable)} />
                            </>
                        )}
                        empty={
                            category === 'all' ? (
                                <EmptyState icon="sparkles" title="No services yet" action={add}>
                                    Add what customers can book.
                                </EmptyState>
                            ) : (
                                <EmptyState icon="sparkles" title="No services in this category" />
                            )
                        }
                    />
                </>
            )}
        </>
    );
}
