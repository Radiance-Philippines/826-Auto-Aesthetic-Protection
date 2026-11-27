import { Switch, SwitchThumb } from '@ui-sdk/react/switch';
import { type FormEvent, useRef, useState } from 'react';
import { Link } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { type Column, DataTable, useClientTable } from '../../ui/DataTable';
import { confirm, Modal, ModalActions } from '../../ui/Dialog';
import { Icon } from '../../ui/icons';
import { ActionMenu } from '../../ui/Menu';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { toast } from '../../ui/toast';
import { Tick, Tone } from '../bookings/parts';
import { useReorder } from '../branches/useReorder';
import { Upload } from './Upload';

type Photo = { id: number; thumbUrl: string; caption: string | null; category: string; categoryLabel: string; isFeatured: boolean };

type Bulk = 'categorize' | 'feature' | 'unfeature' | 'delete';

/** What a row is called: its caption, or which photo it is */
const name = (photo: Photo) => photo.caption ?? `photo ${photo.id}`;

const short = (caption: string) => (caption.length > 40 ? `${caption.slice(0, 40)}…` : caption);

function Categorize({ categories, count, onSend, onCancel }: { categories: Option[]; count: number; onSend: (category: string) => Promise<void>; onCancel: () => void }) {
    const [category, setCategory] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();

        if (!category) {
            setError('Pick a category.');

            return;
        }

        setBusy(true);
        await onSend(category);
        setBusy(false);
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            <SelectField label="Category" hint={`For the ${count} selected.`} error={error} options={categories} value={category} onChange={setCategory} />
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="tag" variant="primary" busy={busy}>
                    Change category
                </Button>
            </ModalActions>
        </form>
    );
}

export function PhotoList() {
    const { data, error, reload } = useResource<{ data: Photo[]; categories: Option[] }>('/photos');
    const [category, setCategory] = useState('all');
    const [featured, setFeatured] = useState('all');
    const [selected, setSelected] = useState<number[]>([]);
    /** Switches just pressed, shown while the server saves them */
    const [pressed, setPressed] = useState<Record<number, boolean>>({});
    const [uploading, setUploading] = useState(false);
    const [categorizing, setCategorizing] = useState(false);
    const add = useRef<HTMLDivElement>(null);
    const order = useReorder(data?.data ?? [], '/photos', reload, name);

    const isFeatured = (photo: Photo) => pressed[photo.id] ?? photo.isFeatured;
    const filtered = category !== 'all' || featured !== 'all';
    const shown = order.rows.filter((photo) => (category === 'all' || photo.category === category) && (featured === 'all' || isFeatured(photo) === (featured === 'yes')));
    // Only what still passes the filters is acted on
    const chosen = selected.filter((id) => shown.some((photo) => photo.id === id));

    const refresh = () => {
        setPressed({});
        order.refresh();
    };

    const toggle = async (photo: Photo, on: boolean) => {
        setPressed((current) => ({ ...current, [photo.id]: on }));

        try {
            await api('PATCH', `/photos/${photo.id}/featured`, { isFeatured: on });
            toast.success(on ? `${name(photo)} is featured` : `${name(photo)} is not featured`);
        } catch (failure) {
            toast.error(errorMessage(failure));
            refresh();
        }
    };

    const bulk = async (action: Bulk, done: string, to?: string) => {
        try {
            await api('POST', '/photos/bulk', { action, ids: chosen, category: to });
            toast.success(done);
            setSelected([]);
            setCategorizing(false);
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        refresh();
    };

    const removeChosen = async () => {
        if (await confirm({ title: 'Delete selected photos', description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true })) {
            await bulk('delete', 'Deleted');
            add.current?.querySelector('button')?.focus();
        }
    };

    const columns: Column<Photo>[] = [
        {
            id: 'photo',
            header: 'Photo',
            primary: true,
            cell: (photo) => (
                <span className="flex items-center gap-3">
                    <Tick label={`Select ${name(photo)}`} checked={selected.includes(photo.id)} onChange={(on) => setSelected((current) => (on ? [...current, photo.id] : current.filter((id) => id !== photo.id)))} />
                    <img src={photo.thumbUrl} alt="" loading="lazy" className="h-16 w-20 shrink-0 rounded-md bg-muted object-cover" />
                </span>
            ),
        },
        { id: 'caption', header: 'Caption', cell: (photo) => (photo.caption ? <span className="break-words">{short(photo.caption)}</span> : <span className="text-muted-foreground">No caption</span>) },
        { id: 'category', header: 'Category', cell: (photo) => <Tone tone="primary">{photo.categoryLabel}</Tone> },
        {
            id: 'featured',
            header: 'Featured',
            align: 'center',
            cell: (photo) => (
                <Switch checked={isFeatured(photo)} onCheckedChange={(on) => void toggle(photo, on)} aria-label={`Featured: ${name(photo)}`} className="align-middle">
                    <SwitchThumb />
                </Switch>
            ),
        },
    ];

    const table = useClientTable(shown, columns, { searchText: (photo) => photo.caption ?? '', pageSize: 48 });
    const movable = !filtered && !table.state.search && !table.state.sort;
    const onPage = table.rows.map((photo) => photo.id);
    const pageChosen = onPage.filter((id) => selected.includes(id));

    const filter = (set: (value: string) => void) => (value: string) => {
        set(value);
        table.onStateChange({ ...table.state, page: 1 });
    };

    const upload = (
        <Button icon="upload" variant="primary" onClick={() => setUploading(true)}>
            Upload photos
        </Button>
    );

    return (
        <>
            <PageHeader title="Photos" description="The gallery, by category; featured ones also show on the Home page." actions={<div ref={add}>{upload}</div>} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={6} />
            ) : data.data.length === 0 ? (
                <EmptyState icon="image" title="No photos yet" action={upload}>
                    Upload pictures of your work.
                </EmptyState>
            ) : (
                <>
                    <div className="grid max-w-xl items-start gap-3 sm:grid-cols-2">
                        <SelectField label="Category" hint="Show one category." options={[{ value: 'all', label: 'All categories' }, ...data.categories]} value={category} onChange={filter(setCategory)} />
                        <SelectField
                            label="Featured"
                            hint="Show only featured, or only the rest."
                            options={[
                                { value: 'all', label: 'All' },
                                { value: 'yes', label: 'Yes' },
                                { value: 'no', label: 'No' },
                            ]}
                            value={featured}
                            onChange={filter(setFeatured)}
                        />
                    </div>
                    {!movable && <p className="m-0 text-xs text-muted-foreground">Clear the filters, search and sorting to move photos.</p>}

                    <div className="flex min-h-11 flex-wrap items-center gap-3">
                        <label className="flex cursor-pointer items-center gap-2 text-sm pointer-coarse:min-h-11">
                            <Tick
                                label="Select all on this page"
                                checked={onPage.length > 0 && pageChosen.length === onPage.length}
                                indeterminate={pageChosen.length > 0 && pageChosen.length < onPage.length}
                                onChange={(on) => setSelected((current) => [...current.filter((id) => !onPage.includes(id)), ...(on ? onPage : [])])}
                            />
                            Select all on this page
                        </label>
                        {chosen.length > 0 && (
                            <>
                                <span role="status" className="text-sm font-medium">
                                    {chosen.length} selected
                                </span>
                                <Button icon="tag" size="sm" onClick={() => setCategorizing(true)}>
                                    Change category
                                </Button>
                                <Button icon="star" size="sm" onClick={() => void bulk('feature', 'Featured on Home')}>
                                    Feature on Home
                                </Button>
                                <Button icon="circle-minus" size="sm" onClick={() => void bulk('unfeature', 'Removed from Home')}>
                                    Remove from Home
                                </Button>
                                <Button icon="trash-2" size="sm" variant="destructive" onClick={() => void removeChosen()}>
                                    Delete selected
                                </Button>
                            </>
                        )}
                    </div>

                    <DataTable
                        {...table}
                        caption="Photos"
                        columns={columns}
                        rowId={(photo) => String(photo.id)}
                        rowName={name}
                        searchHint="By caption."
                        onReorder={filtered ? undefined : (ids) => order.reorderPage(ids, table.state.page, table.pageSize)}
                        actions={(photo) => (
                            <>
                                <Link to={`/photos/${photo.id}/edit`} className={buttonClass('outline', 'sm')} aria-label={`Edit ${name(photo)}`}>
                                    <Icon name="pencil" />
                                    Edit
                                </Link>
                                <ActionMenu label={`More actions for ${name(photo)}`} actions={order.moves(photo, movable)} />
                            </>
                        )}
                        empty={<EmptyState icon="image" title="No photos match these filters" />}
                    />
                </>
            )}

            <Modal open={uploading} onClose={() => setUploading(false)} title="Upload photos">
                {uploading && data && <Upload categories={data.categories} onAdded={refresh} onDone={() => setUploading(false)} onCancel={() => setUploading(false)} />}
            </Modal>
            <Modal open={categorizing} onClose={() => setCategorizing(false)} title="Change category">
                {categorizing && data && <Categorize categories={data.categories} count={chosen.length} onSend={(to) => bulk('categorize', 'Category changed', to)} onCancel={() => setCategorizing(false)} />}
            </Modal>
        </>
    );
}
