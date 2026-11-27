import { Switch, SwitchThumb } from '@ui-sdk/react/switch';
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

type Video = { id: number; title: string; youtubeUrl: string; watchUrl: string; caption: string | null; isPublished: boolean };

function VideoForm({ video, onDone, onCancel }: { video: Video | null; onDone: (another: boolean) => void; onCancel: () => void }) {
    const [youtubeUrl, setUrl] = useState(video?.youtubeUrl ?? '');
    const [title, setTitle] = useState(video?.title ?? '');
    const [caption, setCaption] = useState(video?.caption ?? '');
    const [isPublished, setPublished] = useState(video?.isPublished ?? true);
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';

        void submit(async () => {
            await api(video ? 'PATCH' : 'POST', video ? `/videos/${video.id}` : '/videos', { youtubeUrl, title, caption, isPublished });
            toast.success(video ? 'Saved' : 'Created');
            onDone(another);
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <TextField label="YouTube link" hint="Videos are not uploaded here. Upload to YouTube first, then paste its link." type="url" placeholder="https://www.youtube.com/watch?v=…" error={errors.youtubeUrl} value={youtubeUrl} onValueChange={setUrl} required maxLength={255} autoComplete="off" />
            <TextField label="Title" hint="Shown over the video on the site." error={errors.title} value={title} onValueChange={setTitle} required maxLength={120} autoComplete="off" />
            <TextField label="Caption" hint="Optional, shown under the video." error={errors.caption} value={caption} onValueChange={setCaption} maxLength={200} autoComplete="off" />
            <SwitchField label="Shown on the site" hint="Off keeps it in this list only." checked={isPublished} onChange={setPublished} />
            <ModalActions onCancel={onCancel}>
                {!video && (
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                )}
                <Button type="submit" icon={video ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {video ? 'Save changes' : 'Create'}
                </Button>
            </ModalActions>
        </form>
    );
}

export default function Videos() {
    const { data, error, reload } = useResource<{ data: Video[] }>('/videos');
    const order = useReorder(data?.data ?? [], '/videos', reload, (video) => video.title);
    /** Switches just pressed, shown while the server saves them */
    const [pressed, setPressed] = useState<Record<number, boolean>>({});
    const [editing, setEditing] = useState<Video | 'new' | null>(null);
    const [round, setRound] = useState(0);
    const add = useRef<HTMLDivElement>(null);

    const publish = async (video: Video, isPublished: boolean) => {
        setPressed((current) => ({ ...current, [video.id]: isPublished }));

        try {
            await api('PATCH', `/videos/${video.id}/published`, { isPublished });
            toast.success(isPublished ? `${video.title} is shown` : `${video.title} is hidden`);
        } catch (failure) {
            toast.error(errorMessage(failure));
            setPressed((current) => ({ ...current, [video.id]: video.isPublished }));
        }

        order.refresh();
    };

    const columns: Column<Video>[] = [
        {
            id: 'title',
            header: 'Title',
            primary: true,
            sortValue: (video) => video.title,
            cell: (video) => (
                <>
                    <span className="block break-words">{video.title}</span>
                    <a href={video.watchUrl} target="_blank" rel="noreferrer" className="block text-xs font-normal break-all text-muted-foreground underline">
                        {video.watchUrl}
                    </a>
                </>
            ),
        },
        {
            id: 'shown',
            header: 'Shown',
            align: 'center',
            cell: (video) => (
                <Switch checked={pressed[video.id] ?? video.isPublished} onCheckedChange={(on) => void publish(video, on)} aria-label={`Shown: ${video.title}`} className="align-middle">
                    <SwitchThumb />
                </Switch>
            ),
        },
    ];

    const table = useClientTable(order.rows, columns, { searchText: (video) => video.title });

    const remove = async (video: Video) => {
        if (!(await confirm({ title: `Delete ${video.title}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/videos/${video.id}`);
            toast.success('Deleted');
            add.current?.querySelector('button')?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        order.refresh();
    };

    const addButton = (
        <Button icon="plus" variant="primary" onClick={() => setEditing('new')}>
            Add a video
        </Button>
    );

    return (
        <>
            <PageHeader title="Videos" description="Videos on the Our work page. Paste a YouTube link: nothing is uploaded." actions={<div ref={add}>{addButton}</div>} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <DataTable
                    {...table}
                    caption="Videos"
                    columns={columns}
                    rowId={(video) => String(video.id)}
                    rowName={(video) => video.title}
                    searchHint="By title."
                    onReorder={(ids) => order.reorderPage(ids, table.state.page, table.pageSize)}
                    actions={(video) => (
                        <>
                            <Button icon="pencil" size="sm" aria-label={`Edit ${video.title}`} onClick={() => setEditing(video)}>
                                Edit
                            </Button>
                            <ActionMenu
                                label={`More actions for ${video.title}`}
                                actions={[...order.moves(video, !table.state.search && !table.state.sort), { id: 'delete', label: 'Delete', icon: 'trash-2', destructive: true, onSelect: () => void remove(video) }]}
                            />
                        </>
                    )}
                    empty={
                        <EmptyState icon="video" title="No videos yet" action={addButton}>
                            Upload to YouTube, then add its link here.
                        </EmptyState>
                    }
                />
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' || editing === null ? 'Create video' : `Edit ${editing.title}`}>
                {editing !== null && (
                    <VideoForm
                        key={editing === 'new' ? `new-${round}` : editing.id}
                        video={editing === 'new' ? null : editing}
                        onCancel={() => setEditing(null)}
                        onDone={(another) => {
                            setRound((current) => current + 1);

                            if (!another) {
                                setEditing(null);
                            }

                            setPressed({});
                            order.refresh();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
