import { type FormEvent, useRef, useState } from 'react';

import { api, ApiError, errorMessage } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { type Column, DataTable, useClientTable } from '../ui/DataTable';
import { confirm, Modal, ModalActions } from '../ui/Dialog';
import { SwitchField, TextField } from '../ui/Field';
import { toFormData } from '../ui/FileUpload';
import { Icon } from '../ui/icons';
import { NumberField } from '../ui/NumberField';
import { PageHeader } from '../ui/PageHeader';
import { type Option, SelectField } from '../ui/Select';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { ApkInput } from './app-releases/ApkInput';
import { Alert, Tone, useSubmit } from './bookings/parts';

type Release = {
    id: number;
    app: string;
    appLabel: string;
    version: string;
    versionCode: number;
    size: number;
    sizeLabel: string;
    notes: string | null;
    mandatory: boolean;
    isPublished: boolean;
    added: string | null;
};

const yesNo = (value: boolean) => (
    <>
        <Icon name={value ? 'circle-check' : 'circle-x'} className={`inline size-5 ${value ? 'text-primary' : 'text-muted-foreground'}`} />
        <span className="sr-only">{value ? 'Yes' : 'No'}</span>
    </>
);

const columns: Column<Release>[] = [
    {
        id: 'version',
        header: 'Version',
        primary: true,
        sortValue: (release) => release.version,
        cell: (release) => (
            <>
                <span className="block">{release.version}</span>
                <span className="block text-xs font-normal text-muted-foreground">Code {release.versionCode}</span>
            </>
        ),
    },
    { id: 'app', header: 'App', cell: (release) => <Tone tone="gray">{release.appLabel}</Tone> },
    { id: 'size', header: 'Size', align: 'end', cell: (release) => <span className="tabular-nums">{release.sizeLabel}</span> },
    { id: 'published', header: 'Published', align: 'center', cell: (release) => yesNo(release.isPublished) },
    { id: 'mandatory', header: 'Must update', align: 'center', cell: (release) => yesNo(release.mandatory) },
    { id: 'added', header: 'Added', cell: (release) => release.added },
];

function ReleaseForm({ release, apps, onDone, onCancel }: { release: Release | null; apps: Option[]; onDone: () => void; onCancel: () => void }) {
    const [app, setApp] = useState(release?.app ?? 'customer');
    const [version, setVersion] = useState(release?.version ?? '');
    const [versionCode, setVersionCode] = useState<number | null>(release?.versionCode ?? null);
    const [apk, setApk] = useState<File[]>([]);
    const [notes, setNotes] = useState(release?.notes ?? '');
    const [mandatory, setMandatory] = useState(release?.mandatory ?? false);
    const [isPublished, setPublished] = useState(release?.isPublished ?? false);
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const body = toFormData({ app, version, versionCode, notes, mandatory, isPublished, apk: apk[0] });

            try {
                await api('POST', release ? `/app-releases/${release.id}` : '/app-releases', body);
            } catch (failure) {
                // The server's post size limit answers before the form is read
                throw failure instanceof ApiError && failure.status === 413 ? new ApiError(413, 'That file is too big for the server. Try a smaller one.') : failure;
            }

            toast.success(release ? 'Saved' : 'Created');
            onDone();
        });
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <SelectField label="App" hint="Which Android app this build is." error={errors.app} options={apps} value={app} onChange={setApp} />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label="Version" hint="Like 0.1.0: the same as the version in the app." error={errors.version} value={version} onValueChange={setVersion} required maxLength={20} autoComplete="off" />
                <NumberField label="Version code" hint="A whole number that goes up with every release." error={errors.versionCode} value={versionCode} onChange={setVersionCode} min={1} format={{ useGrouping: false }} />
            </div>
            <ApkInput label="APK file" hint="The signed release APK. Kept private; people download it from the Download page." error={errors.apk} value={apk} onChange={setApk} replacing={release !== null} />
            <TextAreaField label="Notes" hint="What changed, shown on the Download page and in the app." error={errors.notes} value={notes} onValueChange={setNotes} rows={4} maxLength={2000} />
            <SwitchField label="Everyone must update" hint="The app asks people to install this version before they continue." checked={mandatory} onChange={setMandatory} />
            <SwitchField label="Published" hint="Off keeps it hidden: nobody can download a draft." checked={isPublished} onChange={setPublished} />
            {busy && apk[0] && (
                <p role="status" className="text-sm text-muted-foreground">
                    Uploading the APK. Keep this page open.
                </p>
            )}
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon={release ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {release ? 'Save changes' : 'Create'}
                </Button>
            </ModalActions>
        </form>
    );
}

export default function AppReleases() {
    const { data, error, reload } = useResource<{ data: Release[]; apps: Option[] }>('/app-releases');
    const [editing, setEditing] = useState<Release | 'new' | null>(null);
    const add = useRef<HTMLDivElement>(null);
    const table = useClientTable(data?.data ?? [], columns, { searchText: (release) => release.version });

    const remove = async (release: Release) => {
        if (!(await confirm({ title: `Delete ${release.version}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/app-releases/${release.id}`);
            toast.success('Deleted');
            add.current?.querySelector('button')?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        reload();
    };

    const newButton = (
        <Button icon="plus" variant="primary" onClick={() => setEditing('new')}>
            New release
        </Button>
    );
    const editingTitle = editing === 'new' || editing === null ? 'New release' : `Edit ${editing.version}`;

    return (
        <>
            <PageHeader title="App releases" description="The newest published build of each app is what the Download page and the app’s update check offer." actions={<div ref={add}>{newButton}</div>} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <DataTable
                    {...table}
                    caption="App releases"
                    columns={columns}
                    rowId={(release) => String(release.id)}
                    rowName={(release) => `${release.appLabel} ${release.version}`}
                    searchHint="By version, like “0.3.0”."
                    actions={(release) => (
                        <>
                            <Button icon="pencil" size="sm" aria-label={`Edit ${release.appLabel} ${release.version}`} onClick={() => setEditing(release)}>
                                Edit
                            </Button>
                            <Button icon="trash-2" size="sm" variant="destructive" aria-label={`Delete ${release.appLabel} ${release.version}`} onClick={() => remove(release)}>
                                Delete
                            </Button>
                        </>
                    )}
                    empty={
                        <EmptyState icon="smartphone" title="No app releases yet" action={newButton}>
                            Upload the first APK to offer it on the Download page.
                        </EmptyState>
                    }
                />
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editingTitle}>
                {editing !== null && data && (
                    <ReleaseForm
                        key={editing === 'new' ? 'new' : editing.id}
                        release={editing === 'new' ? null : editing}
                        apps={data.apps}
                        onCancel={() => setEditing(null)}
                        onDone={() => {
                            setEditing(null);
                            reload();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
