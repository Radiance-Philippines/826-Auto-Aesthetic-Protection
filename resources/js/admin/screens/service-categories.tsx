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
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Alert, useSubmit } from './bookings/parts';
import { slugify } from './branches/slugify';
import { useReorder } from './branches/useReorder';
import { YesNo } from './branches/YesNo';

type Category = { id: number; name: string; slug: string; description: string | null; isActive: boolean; services: number };

const columns: Column<Category>[] = [
    { id: 'name', header: 'Name', primary: true, cell: (category) => <span className="break-words">{category.name}</span> },
    { id: 'services', header: 'Services', align: 'center', cell: (category) => <span className="tabular-nums">{category.services}</span> },
    { id: 'shown', header: 'Shown', align: 'center', cell: (category) => <YesNo yes={category.isActive} /> },
];

function CategoryForm({ category, onDone, onCancel }: { category: Category | null; onDone: (another: boolean) => void; onCancel: () => void }) {
    const [name, setName] = useState(category?.name ?? '');
    const [slug, setSlug] = useState(category?.slug ?? '');
    const [description, setDescription] = useState(category?.description ?? '');
    const [isActive, setActive] = useState(category?.isActive ?? true);
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';

        void submit(async () => {
            await api(category ? 'PATCH' : 'POST', category ? `/service-categories/${category.id}` : '/service-categories', { name, slug, description, isActive });
            toast.success(category ? 'Saved' : 'Created');
            onDone(another);
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <TextField label="Name" hint="The group’s heading on the services page." error={errors.name} value={name} onValueChange={setName} onBlur={() => !category && setSlug(slugify(name))} required maxLength={80} autoComplete="off" />
            <TextField label="Slug" hint="Its short name in web addresses: letters, numbers, dashes and underscores." error={errors.slug} value={slug} onValueChange={setSlug} required maxLength={80} autoComplete="off" autoCapitalize="none" spellCheck={false} />
            <TextAreaField label="Description" hint="Optional. Shown under the heading." error={errors.description} value={description} onValueChange={setDescription} rows={3} maxLength={1000} />
            <SwitchField label="Shown on the site" hint="Off hides the group and stops its services being booked." checked={isActive} onChange={setActive} />
            <ModalActions onCancel={onCancel}>
                {!category && (
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                )}
                <Button type="submit" icon={category ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {category ? 'Save changes' : 'Create'}
                </Button>
            </ModalActions>
        </form>
    );
}

export default function ServiceCategories() {
    const { data, error, reload } = useResource<{ data: Category[] }>('/service-categories');
    const order = useReorder(data?.data ?? [], '/service-categories', reload, (category) => category.name);
    const table = useClientTable(order.rows, columns, { pageSize: 50 });
    const [editing, setEditing] = useState<Category | 'new' | null>(null);
    /** A fresh form after "Create & create another" */
    const [round, setRound] = useState(0);
    const add = useRef<HTMLDivElement>(null);

    const remove = async (category: Category) => {
        if (!(await confirm({ title: `Delete ${category.name}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/service-categories/${category.id}`);
            toast.success('Deleted');
            add.current?.querySelector('button')?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        order.refresh();
    };

    const addButton = (
        <Button icon="plus" variant="primary" onClick={() => setEditing('new')}>
            Add a category
        </Button>
    );

    return (
        <>
            <PageHeader title="Categories" description="The groups on the services page. Only an empty category can be deleted." actions={<div ref={add}>{addButton}</div>} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <DataTable
                    {...table}
                    caption="Categories"
                    columns={columns}
                    rowId={(category) => String(category.id)}
                    rowName={(category) => category.name}
                    onReorder={(ids) => order.reorderPage(ids, table.state.page, table.pageSize)}
                    actions={(category) => (
                        <>
                            <Button icon="pencil" size="sm" aria-label={`Edit ${category.name}`} onClick={() => setEditing(category)}>
                                Edit
                            </Button>
                            <ActionMenu
                                label={`More actions for ${category.name}`}
                                actions={[...order.moves(category), ...(category.services === 0 ? [{ id: 'delete', label: 'Delete', icon: 'trash-2' as const, destructive: true, onSelect: () => void remove(category) }] : [])]}
                            />
                        </>
                    )}
                    empty={
                        <EmptyState icon="layout-grid" title="No categories yet" action={addButton}>
                            Add the groups your services are listed under.
                        </EmptyState>
                    }
                />
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' || editing === null ? 'Create category' : `Edit ${editing.name}`}>
                {editing !== null && (
                    <CategoryForm
                        key={editing === 'new' ? `new-${round}` : editing.id}
                        category={editing === 'new' ? null : editing}
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
