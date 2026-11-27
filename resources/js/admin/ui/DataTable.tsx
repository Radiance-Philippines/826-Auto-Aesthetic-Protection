import { Input } from '@ui-sdk/react/input';
import { Pagination, PaginationEllipsis, PaginationItem, PaginationLink, PaginationList, PaginationNext, PaginationPrevious } from '@ui-sdk/react/pagination';
import { type ReactNode, useId, useState } from 'react';

import { buttonClass } from './Button';
import { cx } from './cx';
import { controlClass } from './Field';
import { Icon } from './icons';
import { SortableBody, SortableHandle, SortableRow } from './Sortable';

export type Column<Row> = {
    id: string;
    header: string;
    cell: (row: Row) => ReactNode;
    /** Makes the column sortable */
    sortValue?: (row: Row) => string | number;
    align?: 'center' | 'end';
    /** The row's name: its header cell, and the card's title on a narrow screen */
    primary?: boolean;
};

/** What the person asked to see. A server-paged screen turns it into ?search=&sort=&desc=1&page= */
export type TableState = { search: string; sort: { id: string; desc: boolean } | null; page: number };

const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

/** Search, sort and pages worked out in the browser, for a list short enough to arrive whole */
export function useClientTable<Row>(rows: Row[], columns: Column<Row>[], { searchText, pageSize = 25 }: { searchText?: (row: Row) => string; pageSize?: number } = {}) {
    const [state, setState] = useState<TableState>({ search: '', sort: null, page: 1 });
    const query = state.search.trim().toLowerCase();
    let found = query && searchText ? rows.filter((row) => searchText(row).toLowerCase().includes(query)) : rows;
    const sortValue = columns.find((column) => column.id === state.sort?.id)?.sortValue;

    if (sortValue) {
        const way = state.sort?.desc ? -1 : 1;
        found = [...found].sort((a, b) => {
            const [x, y] = [sortValue(a), sortValue(b)];

            return way * (typeof x === 'number' && typeof y === 'number' ? x - y : collator.compare(String(x), String(y)));
        });
    }

    const page = Math.min(state.page, Math.max(1, Math.ceil(found.length / pageSize)));

    return { state: { ...state, page }, onStateChange: setState, rows: found.slice((page - 1) * pageSize, page * pageSize), total: found.length, pageSize };
}

type Props<Row> = {
    /** Names the table for screen readers */
    caption: string;
    columns: Column<Row>[];
    /** The rows of the page on screen, already searched and sorted */
    rows: Row[];
    rowId: (row: Row) => string;
    rowName: (row: Row) => string;
    state: TableState;
    onStateChange: (state: TableState) => void;
    total: number;
    pageSize: number;
    /** Shows the search box, with this under it */
    searchHint?: string;
    actions?: (row: Row) => ReactNode;
    /** The visible rows' ids in their new order. Rows are only movable in their own order (no search, no sort). */
    onReorder?: (ids: string[]) => void;
    /** When there is nothing at all */
    empty: ReactNode;
};

export function DataTable<Row>({ caption, columns, rows, rowId, rowName, state, onStateChange, total, pageSize, searchHint, actions, onReorder, empty }: Props<Row>) {
    const searchId = useId();
    const sorted = columns.find((column) => column.id === state.sort?.id);
    const movable = !state.search && !state.sort;
    const byId = new Map(rows.map((row) => [rowId(row), row]));

    if (total === 0 && !state.search) {
        return <>{empty}</>;
    }

    // First press ascending, then descending, then back to the table's own order
    const sortBy = (id: string) =>
        onStateChange({ ...state, page: 1, sort: state.sort?.id !== id ? { id, desc: false } : state.sort.desc ? null : { id, desc: true } });

    const cell = 'border-t border-border px-3 py-2.5 align-middle';
    const align = (column: Column<Row>) => (column.align === 'center' ? 'text-center' : column.align === 'end' ? 'text-end' : 'text-start');

    const cells = (row: Row) => (
        <>
            {columns.map((column) =>
                column.primary ? (
                    <th key={column.id} scope="row" className={cx(cell, 'text-start font-medium')}>
                        {column.cell(row)}
                    </th>
                ) : (
                    <td key={column.id} data-label={column.header} className={cx(cell, align(column))}>
                        {column.cell(row)}
                    </td>
                ),
            )}
            {actions && (
                <td className={cx(cell, 'text-end')}>
                    <div className="flex flex-wrap justify-end gap-1.5 @max-[36rem]:justify-start @max-[36rem]:pt-2">{actions(row)}</div>
                </td>
            )}
        </>
    );

    const handle = (row: Row) => (
        <td className={cx(cell, 'w-10 !pe-0 @max-[36rem]:float-end')}>
            <SortableHandle
                value={rowId(row)}
                aria-label={`Move ${rowName(row)}`}
                aria-describedby={`${searchId}-moving`}
                disabled={!movable}
                title={movable ? 'Drag, or press Space and use the arrows' : 'Clear the search and sorting to move rows'}
                className={cx(buttonClass('ghost', 'sm', true), 'cursor-grab touch-none shadow-none disabled:cursor-not-allowed disabled:opacity-40')}
            >
                <Icon name="grip-vertical" />
            </SortableHandle>
        </td>
    );

    return (
        <div className="space-y-3">
            {searchHint && (
                <div className="max-w-sm">
                    <label htmlFor={searchId} className="sr-only">
                        Search {caption.toLowerCase()}
                    </label>
                    <Input
                        id={searchId}
                        type="search"
                        value={state.search}
                        onChange={(event) => onStateChange({ ...state, page: 1, search: event.currentTarget.value })}
                        placeholder="Search"
                        aria-describedby={`${searchId}-hint`}
                        className={controlClass}
                    />
                    <p id={`${searchId}-hint`} className="mt-1 text-xs text-muted-foreground">
                        {searchHint}
                    </p>
                </div>
            )}

            <p role="status" className="sr-only">
                {sorted ? `Sorted by ${sorted.header}, ${state.sort?.desc ? 'descending' : 'ascending'}.` : ''} {state.search ? `${total} found.` : ''}
            </p>
            {onReorder && (
                <p id={`${searchId}-moving`} className="sr-only">
                    Press Space to pick the row up, arrow keys to move it, Space to drop it, Escape to cancel.
                </p>
            )}

            <div className="table-wrap overflow-hidden rounded-xl border border-border bg-card">
                <table className="data-table w-full border-collapse text-sm">
                    <caption className="sr-only">{caption}</caption>
                    <thead className="bg-muted/60">
                        <tr>
                            {onReorder && (
                                <th scope="col" className="w-10 px-3 py-2">
                                    <span className="sr-only">Order</span>
                                </th>
                            )}
                            {columns.map((column) => (
                                <th
                                    key={column.id}
                                    scope="col"
                                    aria-sort={state.sort?.id === column.id ? (state.sort.desc ? 'descending' : 'ascending') : undefined}
                                    className={cx('px-3 py-2 text-xs font-medium text-muted-foreground', align(column))}
                                >
                                    {column.sortValue ? (
                                        <button type="button" onClick={() => sortBy(column.id)} className="-mx-2 inline-flex min-h-8 cursor-pointer items-center gap-1 rounded-md border-0 bg-transparent px-2 font-medium text-inherit hover:bg-muted pointer-coarse:min-h-11">
                                            <Icon name={state.sort?.id !== column.id ? 'arrow-up-down' : state.sort.desc ? 'arrow-down' : 'arrow-up'} className="size-3.5" />
                                            {column.header}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            ))}
                            {actions && (
                                <th scope="col" className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            )}
                        </tr>
                    </thead>

                    {onReorder ? (
                        <SortableBody value={rows.map(rowId)} onValueChange={onReorder} labels={Object.fromEntries(rows.map((row) => [rowId(row), rowName(row)]))}>
                            {({ order }: { order: string[] }) =>
                                order.map((id) => {
                                    const row = byId.get(id);

                                    return row ? (
                                        <SortableRow key={id} value={id} className="bg-card data-grabbed:relative data-grabbed:z-10 data-grabbed:translate-y-(--drag-y,0) data-grabbed:shadow-lg">
                                            {handle(row)}
                                            {cells(row)}
                                        </SortableRow>
                                    ) : null;
                                })
                            }
                        </SortableBody>
                    ) : (
                        <tbody>
                            {rows.map((row) => (
                                <tr key={rowId(row)}>{cells(row)}</tr>
                            ))}
                        </tbody>
                    )}
                </table>

                {total === 0 && (
                    <p className="border-t border-border px-3 py-6 text-center text-sm text-muted-foreground">Nothing matches “{state.search}”.</p>
                )}
            </div>

            {total > pageSize && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground tabular-nums">
                        {(state.page - 1) * pageSize + 1}–{Math.min(state.page * pageSize, total)} of {total}
                    </p>
                    <Pagination aria-label={`${caption} pages`} page={state.page} total={total} pageSize={pageSize} onPageChange={(page) => onStateChange({ ...state, page })}>
                        <PaginationList className="m-0 flex list-none flex-wrap items-center gap-1 p-0">
                            {({ items }) => (
                                <>
                                    <PaginationItem>
                                        <PaginationPrevious aria-label="Previous page" className={buttonClass('outline', 'sm', true)}>
                                            <Icon name="chevron-left" />
                                        </PaginationPrevious>
                                    </PaginationItem>
                                    {items.map((item) => (
                                        <PaginationItem key={item}>
                                            {typeof item === 'number' ? (
                                                <PaginationLink page={item} className={cx(buttonClass('ghost', 'sm'), 'min-w-8 data-current:border-primary data-current:bg-selected data-current:text-selected-foreground')} />
                                            ) : (
                                                <PaginationEllipsis className="px-1 text-muted-foreground" />
                                            )}
                                        </PaginationItem>
                                    ))}
                                    <PaginationItem>
                                        <PaginationNext aria-label="Next page" className={buttonClass('outline', 'sm', true)}>
                                            <Icon name="chevron-right" />
                                        </PaginationNext>
                                    </PaginationItem>
                                </>
                            )}
                        </PaginationList>
                    </Pagination>
                </div>
            )}
        </div>
    );
}
