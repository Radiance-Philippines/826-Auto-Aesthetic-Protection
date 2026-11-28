import { type FormEvent, useRef, useState } from 'react';

import { api, errorMessage } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { type Column, DataTable, useClientTable } from '../ui/DataTable';
import { confirm, Modal, ModalActions } from '../ui/Dialog';
import { SwitchField, TextField } from '../ui/Field';
import { ActionMenu } from '../ui/Menu';
import { PageHeader } from '../ui/PageHeader';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { toast } from '../ui/toast';
import { Alert, useSubmit } from './bookings/parts';
import { useReorder } from './branches/useReorder';
import { YesNo } from './branches/YesNo';

type BackjobType = { id: number; name: string; isActive: boolean; services: number };

const columns: Column<BackjobType>[] = [
    { id: 'name', header: 'Name', primary: true, cell: (type) => <span className="break-words">{type.name}</span> },
    { id: 'services', header: 'Services covered', align: 'center', cell: (type) => <span className="tabular-nums">{type.services}</span> },
    { id: 'used', header: 'In use', align: 'center', cell: (type) => <YesNo yes={type.isActive} /> },
];

function TypeForm({ type, onDone, onCancel }: { type: BackjobType | null; onDone: (another: boolean) => void; onCancel: () => void }) {
    const [name, setName] = useState(type?.name ?? '');
    const [isActive, setActive] = useState(type?.isActive ?? true);
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';

        void submit(async () => {
            await api(type ? 'PATCH' : 'POST', type ? `/backjob-types/${type.id}` : '/backjob-types', { name, isActive });
            toast.success(type ? 'Saved' : 'Created');
            onDone(another);
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <TextField label="Name" hint="Like “Coating peeling” or “Tint bubbling”." error={errors.name} value={name} onValueChange={setName} required maxLength={120} autoComplete="off" />
            <SwitchField label="In use" hint="Off keeps it out of new backjobs." checked={isActive} onChange={setActive} />
            <ModalActions onCancel={onCancel}>
                {!type && (
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                )}
                <Button type="submit" icon={type ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {type ? 'Save changes' : 'Create'}
                </Button>
            </ModalActions>
        </form>
    );
}

export default function BackjobTypes() {
    const { data, error, reload } = useResource<{ data: BackjobType[] }>('/backjob-types');
    const order = useReorder(data?.data ?? [], '/backjob-types', reload, (type) => type.name);
    const table = useClientTable(order.rows, columns, { pageSize: 50 });
    const [editing, setEditing] = useState<BackjobType | 'new' | null>(null);
    const [round, setRound] = useState(0);
    const add = useRef<HTMLDivElement>(null);

    const remove = async (type: BackjobType) => {
        if (!(await confirm({ title: `Delete ${type.name}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/backjob-types/${type.id}`);
            toast.success('Deleted');
            add.current?.querySelector('button')?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        order.refresh();
    };

    const addButton = (
        <Button icon="plus" variant="primary" onClick={() => setEditing('new')}>
            Add a backjob type
        </Button>
    );

    return (
        <>
            <PageHeader title="Backjob types" description="The kinds of backjob the warranty covers. Pick which ones each service covers in Shop → Services." actions={<div ref={add}>{addButton}</div>} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <DataTable
                    {...table}
                    caption="Backjob types"
                    columns={columns}
                    rowId={(type) => String(type.id)}
                    rowName={(type) => type.name}
                    onReorder={(ids) => order.reorderPage(ids, table.state.page, table.pageSize)}
                    actions={(type) => (
                        <>
                            <Button icon="pencil" size="sm" aria-label={`Edit ${type.name}`} onClick={() => setEditing(type)}>
                                Edit
                            </Button>
                            <ActionMenu label={`More actions for ${type.name}`} actions={[...order.moves(type), { id: 'delete', label: 'Delete', icon: 'trash-2', destructive: true, onSelect: () => void remove(type) }]} />
                        </>
                    )}
                    empty={
                        <EmptyState icon="wrench" title="No backjob types yet" action={addButton}>
                            Add the kinds of backjob your warranty covers.
                        </EmptyState>
                    }
                />
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' || editing === null ? 'Create backjob type' : `Edit ${editing.name}`}>
                {editing !== null && (
                    <TypeForm
                        key={editing === 'new' ? `new-${round}` : editing.id}
                        type={editing === 'new' ? null : editing}
                        onCancel={() => setEditing(null)}
                        onDone={(another) => {
                            setRound((current) => current + 1);

                            if (!another) {
                                setEditing(null);
                            }

                            order.refresh();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
