import { type FormEvent, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { api, errorMessage } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { type Column, DataTable, useClientTable } from '../ui/DataTable';
import { DateField } from '../ui/DateField';
import { confirm, Modal, ModalActions } from '../ui/Dialog';
import { TextField } from '../ui/Field';
import { ActionMenu } from '../ui/Menu';
import { PageHeader } from '../ui/PageHeader';
import { type Option, SelectField } from '../ui/Select';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Alert, ChoiceField, Tone, useSubmit } from './bookings/parts';

type Values = { branchId: number | null; kind: string; title: string; firstDay: string; lastDay: string; note: string | null };

type Block = { id: number; when: string; startsOn: string; title: string; kindLabel: string; branch: string | null; values: Values | null };

type Closures = { data: Block[]; branches: Option[]; everyBranch: boolean; defaultBranch: number | null; canCreate: boolean };

const KINDS = [
    { value: 'closed', label: 'Closed (no drop-offs, work pauses)', icon: 'ban' as const },
    { value: 'full', label: 'Fully booked (no new bookings)', icon: 'calendar-off' as const },
];

const WHEN = [
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
    { value: 'all', label: 'All' },
];

const columns: Column<Block>[] = [
    { id: 'when', header: 'When', primary: true, sortValue: (block) => block.startsOn, cell: (block) => <span className="whitespace-nowrap">{block.when}</span> },
    {
        id: 'why',
        header: 'Why',
        cell: (block) => (
            <span className="inline-block min-w-0">
                <span className="block break-words">{block.title}</span>
                <Tone tone={block.kindLabel === 'Closed' ? 'gray' : 'warning'}>{block.kindLabel}</Tone>
            </span>
        ),
    },
    { id: 'branch', header: 'Branch', cell: (block) => block.branch ?? <span className="text-muted-foreground">Every branch</span> },
];

function ClosureForm({ options, block, onDone, onCancel }: { options: Closures; block: Block | null; onDone: (another: boolean) => void; onCancel: () => void }) {
    const start = block?.values;
    const branches = options.everyBranch ? [{ value: 'every', label: 'Every branch' }, ...options.branches] : options.branches;
    const [branch, setBranch] = useState<string | null>(() => {
        if (start) {
            return start.branchId === null ? 'every' : String(start.branchId);
        }

        return options.defaultBranch === null ? (options.everyBranch ? 'every' : null) : String(options.defaultBranch);
    });
    const [kind, setKind] = useState(start?.kind ?? 'closed');
    const [title, setTitle] = useState(start?.title ?? '');
    const [firstDay, setFirstDay] = useState(start?.firstDay ?? '');
    const [lastDay, setLastDay] = useState(start?.lastDay ?? '');
    const [note, setNote] = useState(start?.note ?? '');
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';
        const body = { branchId: branch === null || branch === 'every' ? null : Number(branch), kind, title, firstDay, lastDay, note };

        void submit(async () => {
            await api(block ? 'PATCH' : 'POST', block ? `/branch-blocks/${block.id}` : '/branch-blocks', body);
            toast.success(block ? 'Saved' : 'Created');
            onDone(another);
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <SelectField
                label="Branch"
                hint={options.everyBranch ? '“Every branch” closes them all (Christmas, Holy Week).' : 'The branch whose day this is.'}
                error={errors.branchId}
                options={branches}
                value={branch}
                onChange={setBranch}
                placeholder="Pick a branch"
            />
            <ChoiceField label="What happens" hint="Closed: the shop is shut. Fully booked: it works, but takes no new bookings." error={errors.kind} options={KINDS} value={kind} onChange={setKind} />
            <TextField label="Why" hint="The team sees this." placeholder="Bonifacio Day, brownout, big booking that week…" error={errors.title} value={title} onValueChange={setTitle} required maxLength={120} autoComplete="off" />
            <div className="grid items-start gap-4 sm:grid-cols-2">
                <DateField label="From" hint="The first day." error={errors.firstDay} value={firstDay || null} onChange={(day) => {
                    setFirstDay(day);

                    // One day is the usual closure
                    if (!lastDay || lastDay < day) {
                        setLastDay(day);
                    }
                }} />
                <DateField label="To (the last day)" hint="The same day for one day." error={errors.lastDay} value={lastDay || null} onChange={setLastDay} min={firstDay || undefined} />
            </div>
            <TextAreaField label="Private note" hint="Optional. Only the team sees this." error={errors.note} value={note} onValueChange={setNote} rows={2} maxLength={1000} />
            <ModalActions onCancel={onCancel}>
                {!block && (
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                )}
                <Button type="submit" icon={block ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {block ? 'Save changes' : 'Create'}
                </Button>
            </ModalActions>
        </form>
    );
}

export default function Closures() {
    const [params, setParams] = useSearchParams();
    const asked = params.get('when');
    const when = WHEN.some((each) => each.value === asked) ? asked! : 'upcoming';
    const { data, error, loading, reload } = useResource<Closures>(`/branch-blocks?when=${when}`);
    const table = useClientTable(data?.data ?? [], columns);
    const [editing, setEditing] = useState<Block | 'new' | null>(null);
    const [round, setRound] = useState(0);
    const add = useRef<HTMLDivElement>(null);

    const remove = async (block: Block) => {
        if (!(await confirm({ title: `Delete ${block.title}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/branch-blocks/${block.id}`);
            toast.success('Deleted');
            add.current?.querySelector('button')?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        reload();
    };

    const addButton = data?.canCreate ? (
        <Button icon="plus" variant="primary" onClick={() => setEditing('new')}>
            Add a closure
        </Button>
    ) : null;

    return (
        <>
            <PageHeader title="Closures" description="Whole days a branch is closed or fully booked. Customers can’t pick those days." actions={<div ref={add}>{addButton}</div>} />

            <SelectField label="When" hint="Which closures to list." options={WHEN} value={when} onChange={(value) => setParams(value === 'upcoming' ? {} : { when: value }, { replace: true })} className="max-w-xs" />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : (
                <div aria-busy={loading || undefined}>
                    <DataTable
                        {...table}
                        caption="Closures"
                        columns={columns}
                        rowId={(block) => String(block.id)}
                        rowName={(block) => block.title}
                        actions={(block) =>
                            block.values && (
                                <>
                                    <Button icon="pencil" size="sm" aria-label={`Edit ${block.title}`} onClick={() => setEditing(block)}>
                                        Edit
                                    </Button>
                                    <ActionMenu label={`More actions for ${block.title}`} actions={[{ id: 'delete', label: 'Delete', icon: 'trash-2', destructive: true, onSelect: () => void remove(block) }]} />
                                </>
                            )
                        }
                        empty={
                            <EmptyState icon="calendar-off" title="No closures" action={addButton}>
                                {when === 'upcoming' ? 'Nothing is closed or fully booked from today on.' : undefined}
                            </EmptyState>
                        }
                    />
                </div>
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' || editing === null ? 'Create closure' : `Edit ${editing.title}`}>
                {editing !== null && data && (
                    <ClosureForm
                        key={editing === 'new' ? `new-${round}` : editing.id}
                        options={data}
                        block={editing === 'new' ? null : editing}
                        onCancel={() => setEditing(null)}
                        onDone={(another) => {
                            setRound((current) => current + 1);

                            if (!another) {
                                setEditing(null);
                            }

                            reload();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
