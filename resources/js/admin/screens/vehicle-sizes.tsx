import { type FormEvent, useRef, useState } from 'react';

import { api, errorMessage, fieldErrors } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { type Column, DataTable, useClientTable } from '../ui/DataTable';
import { confirm, Modal, ModalActions } from '../ui/Dialog';
import { SwitchField, TextField } from '../ui/Field';
import { Icon } from '../ui/icons';
import { ActionMenu } from '../ui/Menu';
import { PageHeader } from '../ui/PageHeader';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { toast } from '../ui/toast';

type Size = { id: number; name: string; examples: string | null; isActive: boolean; pricesSet: number };

const columns: Column<Size>[] = [
    {
        id: 'name',
        header: 'Name',
        primary: true,
        sortValue: (size) => size.name,
        cell: (size) => (
            <>
                <span className="block break-words">{size.name}</span>
                {size.examples && <span className="block text-xs font-normal break-words text-muted-foreground">{size.examples}</span>}
            </>
        ),
    },
    { id: 'prices', header: 'Prices set', align: 'center', sortValue: (size) => size.pricesSet, cell: (size) => <span className="tabular-nums">{size.pricesSet}</span> },
    {
        id: 'offered',
        header: 'Offered',
        align: 'center',
        cell: (size) => (
            <>
                <Icon name={size.isActive ? 'circle-check' : 'circle-x'} className={`inline size-5 ${size.isActive ? 'text-primary' : 'text-muted-foreground'}`} />
                <span className="sr-only">{size.isActive ? 'Yes' : 'No'}</span>
            </>
        ),
    },
];

function SizeForm({ size, onDone, onCancel }: { size: Size | null; onDone: () => void; onCancel: () => void }) {
    const [name, setName] = useState(size?.name ?? '');
    const [examples, setExamples] = useState(size?.examples ?? '');
    const [isActive, setActive] = useState(size?.isActive ?? true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        setFailed(null);

        try {
            await api(size ? 'PATCH' : 'POST', size ? `/vehicle-sizes/${size.id}` : '/vehicle-sizes', { name, examples, isActive });
            toast.success(size ? 'Saved' : 'Size added');
            onDone();
        } catch (failure) {
            const fields = fieldErrors(failure);
            setErrors(fields);
            setFailed(Object.keys(fields).length === 0 ? errorMessage(failure) : null);
            setBusy(false);
        }
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && (
                <p role="alert" className="text-sm text-destructive">
                    {failed}
                </p>
            )}
            <TextField label="Name" hint="What the team calls this class." error={errors.name} value={name} onValueChange={setName} placeholder="Medium" required maxLength={60} autoComplete="off" />
            <TextField label="Examples" hint="Helps the team pick the right class." error={errors.examples} value={examples} onValueChange={setExamples} placeholder="Vios, City, Civic" maxLength={160} autoComplete="off" />
            <SwitchField label="Offered when reviewing a unit" hint="Off hides it from the size list. Prices already set are kept." checked={isActive} onChange={setActive} />
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon={size ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {size ? 'Save changes' : 'Add size'}
                </Button>
            </ModalActions>
        </form>
    );
}

export default function VehicleSizes() {
    const { data, error, reload } = useResource<{ data: Size[] }>('/vehicle-sizes');
    /** The order just chosen, shown while the server saves it */
    const [chosen, setChosen] = useState<number[] | null>(null);
    const [editing, setEditing] = useState<Size | 'new' | null>(null);
    const add = useRef<HTMLDivElement>(null);

    const loaded = data?.data ?? [];
    const sizes = chosen ? chosen.flatMap((id) => loaded.find((size) => size.id === id) ?? []) : loaded;
    const table = useClientTable(sizes, columns, { searchText: (size) => `${size.name} ${size.examples ?? ''}`, pageSize: 50 });

    const refresh = () => {
        setChosen(null);
        reload();
    };

    const saveOrder = async (ids: number[]): Promise<boolean> => {
        setChosen(ids);

        try {
            await api('POST', '/vehicle-sizes/reorder', { ids });

            return true;
        } catch (failure) {
            toast.error(fieldErrors(failure).ids ?? errorMessage(failure));
            refresh();

            return false;
        }
    };

    /** The table gives the new order of the rows on screen */
    const reorderPage = (ids: string[]) => {
        const all = sizes.map((size) => size.id);
        all.splice((table.state.page - 1) * table.pageSize, ids.length, ...ids.map(Number));
        saveOrder(all);
    };

    const move = async (size: Size, by: -1 | 1) => {
        const all = sizes.map((each) => each.id);
        const from = all.indexOf(size.id);
        [all[from], all[from + by]] = [all[from + by], all[from]];

        if (await saveOrder(all)) {
            toast.success(`${size.name} moved to position ${from + by + 1} of ${all.length}`);
        }
    };

    const remove = async (size: Size) => {
        const yes = await confirm({ title: `Delete ${size.name}?`, description: 'Its prices go too. Bookings already made keep the class’s name.', label: 'Delete', icon: 'trash-2', destructive: true });

        if (!yes) {
            return;
        }

        try {
            await api('DELETE', `/vehicle-sizes/${size.id}`);
            toast.success('Deleted');
            // The row and its button are gone
            add.current?.querySelector('button')?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        refresh();
    };

    const addButton = (
        <Button icon="plus" variant="primary" onClick={() => setEditing('new')}>
            Add a size
        </Button>
    );

    return (
        <>
            <PageHeader
                title="Vehicle sizes"
                description="The size classes prices are split by. Customers never pick one: the team assigns it to each unit after seeing it."
                actions={<div ref={add}>{addButton}</div>}
            />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <DataTable
                    {...table}
                    caption="Vehicle sizes"
                    columns={columns}
                    rowId={(size) => String(size.id)}
                    rowName={(size) => size.name}
                    searchHint="By name or example, like “Civic”."
                    onReorder={reorderPage}
                    actions={(size) => {
                        const at = sizes.indexOf(size);

                        return (
                            <>
                                <Button icon="pencil" size="sm" aria-label={`Edit ${size.name}`} onClick={() => setEditing(size)}>
                                    Edit
                                </Button>
                                <ActionMenu
                                    label={`More actions for ${size.name}`}
                                    actions={[
                                        { id: 'up', label: 'Move up', icon: 'arrow-up', disabled: at === 0, onSelect: () => move(size, -1) },
                                        { id: 'down', label: 'Move down', icon: 'arrow-down', disabled: at === sizes.length - 1, onSelect: () => move(size, 1) },
                                        { id: 'delete', label: 'Delete', icon: 'trash-2', destructive: true, onSelect: () => remove(size) },
                                    ]}
                                />
                            </>
                        );
                    }}
                    empty={
                        <EmptyState icon="truck" title="No vehicle sizes yet" action={addButton}>
                            Add the classes your prices are split by.
                        </EmptyState>
                    }
                />
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' || editing === null ? 'Add a size' : `Edit ${editing.name}`}>
                {editing !== null && (
                    <SizeForm
                        key={editing === 'new' ? 'new' : editing.id}
                        size={editing === 'new' ? null : editing}
                        onCancel={() => setEditing(null)}
                        onDone={() => {
                            setEditing(null);
                            refresh();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
