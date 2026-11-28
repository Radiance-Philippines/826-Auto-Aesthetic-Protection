import { useRef } from 'react';
import { Link } from 'react-router-dom';

import { useResource } from '../../lib/useResource';
import { buttonClass } from '../../ui/Button';
import { type Column, DataTable, useClientTable } from '../../ui/DataTable';
import { Icon } from '../../ui/icons';
import { ActionMenu } from '../../ui/Menu';
import { PageHeader } from '../../ui/PageHeader';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { Tone } from '../bookings/parts';
import { useReorder } from './useReorder';

type Row = { id: number; name: string; city: string | null; status: string; statusLabel: string; online: string; takingBookings: boolean; dailyCap: number; staff: number };

const tones: Record<string, string> = { open: 'success', coming_soon: 'info', draft: 'gray' };

const columns: Column<Row>[] = [
    {
        id: 'name',
        header: 'Name',
        primary: true,
        sortValue: (branch) => branch.name,
        cell: (branch) => (
            <>
                <span className="block break-words">{branch.name}</span>
                {branch.city && <span className="block text-xs font-normal text-muted-foreground">{branch.city}</span>}
            </>
        ),
    },
    { id: 'status', header: 'Status', cell: (branch) => <Tone tone={tones[branch.status]}>{branch.statusLabel}</Tone> },
    { id: 'online', header: 'Online booking', cell: (branch) => <Tone tone={branch.takingBookings ? 'success' : 'warning'}>{branch.online}</Tone> },
    { id: 'cap', header: 'Daily cap', align: 'center', cell: (branch) => <span className="tabular-nums">{branch.dailyCap}</span> },
    { id: 'staff', header: 'Staff', align: 'center', cell: (branch) => <span className="tabular-nums">{branch.staff}</span> },
];

export function BranchList() {
    const { data, error, reload } = useResource<{ data: Row[] }>('/branches');
    const order = useReorder(data?.data ?? [], '/branches', reload, (branch) => branch.name);
    const table = useClientTable(order.rows, columns, { searchText: (branch) => branch.name });
    const header = useRef<HTMLDivElement>(null);

    const add = (
        <Link to="/branches/create" className={buttonClass('primary')}>
            <Icon name="plus" />
            Add a branch
        </Link>
    );

    return (
        <>
            <PageHeader title="Branches" description="Address, days and daily cap, services, home service, payments, Facebook page and staff, per branch." actions={<div ref={header}>{add}</div>} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <DataTable
                    {...table}
                    caption="Branches"
                    columns={columns}
                    rowId={(branch) => String(branch.id)}
                    rowName={(branch) => branch.name}
                    searchHint="By branch name."
                    onReorder={(ids) => order.reorderPage(ids, table.state.page, table.pageSize)}
                    actions={(branch) => (
                        <>
                            <Link to={`/branches/${branch.id}/edit`} className={buttonClass('outline', 'sm')} aria-label={`Edit ${branch.name}`}>
                                <Icon name="pencil" />
                                Edit
                            </Link>
                            <ActionMenu label={`More actions for ${branch.name}`} actions={order.moves(branch, !table.state.search && !table.state.sort)} />
                        </>
                    )}
                    empty={
                        <EmptyState icon="store" title="No branches yet" action={add}>
                            A new branch starts as a draft; fill in what you know and open it later.
                        </EmptyState>
                    }
                />
            )}
        </>
    );
}
