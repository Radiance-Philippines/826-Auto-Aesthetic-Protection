import { Input } from '@ui-sdk/react/input';
import { type FormEvent, useId, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { manilaToday } from '../../lib/dates';
import { api, errorMessage } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { DateField } from '../ui/DateField';
import { confirm, Modal, ModalActions } from '../ui/Dialog';
import { controlClass } from '../ui/Field';
import { PageHeader } from '../ui/PageHeader';
import { type Option, SelectField } from '../ui/Select';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Alert, Tone, useDebounced, useSubmit } from './bookings/parts';

type Item = { id: number; name: string; months: number | null; ends: string | null; status: 'active' | 'expired' | 'none'; covers: string[]; types: Option[] };

type Job = {
    id: number;
    reference: string;
    name: string;
    phone: string;
    unit: string;
    branch: string | null;
    finished: string | null;
    items: Item[];
    backjobs: { id: number; on: string; service: string | null; type: string; note: string | null; open: boolean }[];
};

type Results = { guest: { id: number; name: string } | null; searched: boolean; data: Job[] };

const statuses = { active: ['success', 'Active'], expired: ['gray', 'Expired'], none: ['gray', 'No warranty'] } as const;

function BackjobForm({ job, onClose, onDone }: { job: Job; onClose: () => void; onDone: () => void }) {
    const [item, setItem] = useState(job.items.length === 1 ? String(job.items[0].id) : null);
    const [type, setType] = useState<string | null>(null);
    const [date, setDate] = useState(manilaToday());
    const [note, setNote] = useState('');
    const { errors, failed, busy, submit } = useSubmit();
    const types = job.items.find((each) => String(each.id) === item)?.types ?? [];

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            await api('POST', '/warranty/backjobs', { bookingId: job.id, bookingItemId: item ? Number(item) : null, backjobTypeId: type, notedOn: date || null, note });
            toast.success('Backjob logged');
            onClose();
            onDone();
        });
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && <Alert title="Not logged">{failed}</Alert>}
            <SelectField
                label="Service"
                hint="The service the problem is with."
                error={errors.bookingItemId}
                options={job.items.map((each) => ({ value: String(each.id), label: each.name }))}
                value={item}
                onChange={(next) => {
                    setItem(next);
                    setType(null);
                }}
            />
            <SelectField key={item ?? 'none'} label="Type" hint="Only the types this service covers, or Other." error={errors.backjobTypeId} options={types} value={type} onChange={setType} placeholder={item ? 'Pick one' : 'Pick the service first'} />
            <DateField label="Date" hint="When it was noticed. Today or earlier." error={errors.notedOn} value={date || null} onChange={setDate} max={manilaToday()} clearable />
            <TextAreaField label="Note" hint="Optional. What the customer reported." error={errors.note} value={note} onValueChange={setNote} maxLength={2000} />
            <ModalActions onCancel={onClose}>
                <Button type="submit" icon="wrench" variant="primary" busy={busy}>
                    Log backjob
                </Button>
            </ModalActions>
        </form>
    );
}

function Result({ job, onLog, onFinish }: { job: Job; onLog: () => void; onFinish: (id: number) => void }) {
    const cell = 'border-t border-border py-2 pe-4 text-start align-top';

    return (
        <section aria-label={`Booking ${job.reference}`} className="rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="m-0 text-base font-semibold break-words">
                        <Link to={`/bookings/${job.id}`} className="underline underline-offset-4">
                            {job.reference}
                        </Link>{' '}
                        · {job.name}
                    </h2>
                    <p className="text-sm break-words text-muted-foreground">
                        {job.unit || '—'} · {job.branch} · {job.phone} · finished {job.finished}
                    </p>
                </div>
                <Button icon="wrench" size="sm" onClick={onLog}>
                    Log a backjob
                </Button>
            </div>

            <div className="table-wrap mt-3">
                <table className="data-table w-full border-collapse text-sm">
                    <caption className="sr-only">Services on {job.reference}</caption>
                    <thead className="text-xs text-muted-foreground">
                        <tr>
                            {['Service done', 'Warranty', 'Covered until', 'Status', 'Covers backjobs for'].map((heading) => (
                                <th key={heading} scope="col" className="py-2 pe-4 text-start font-medium">
                                    {heading}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {job.items.map((item) => (
                            <tr key={item.id}>
                                <th scope="row" className={`${cell} font-medium`}>
                                    {item.name}
                                </th>
                                <td data-label="Warranty" className={cell}>
                                    {item.months ? `${item.months} ${item.months === 1 ? 'month' : 'months'}` : '—'}
                                </td>
                                <td data-label="Covered until" className={cell}>
                                    {item.ends ?? '—'}
                                </td>
                                <td data-label="Status" className={cell}>
                                    <Tone tone={statuses[item.status][0]}>{statuses[item.status][1]}</Tone>
                                </td>
                                <td data-label="Covers backjobs for" className={cell}>
                                    {item.covers.length ? item.covers.join(', ') : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 border-t border-border pt-3">
                <h3 className="m-0 text-sm font-semibold">Backjob history</h3>
                {job.backjobs.length === 0 ? (
                    <p className="mt-1 text-sm text-muted-foreground">None logged.</p>
                ) : (
                    <ul className="m-0 mt-2 list-none space-y-2 p-0">
                        {job.backjobs.map((backjob) => (
                            <li key={backjob.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span className="min-w-0 break-words">
                                    <span className="font-medium">{backjob.on}</span> · {backjob.service ?? 'Service'} · {backjob.type}
                                    {backjob.note && <span className="text-muted-foreground"> · {backjob.note}</span>}
                                </span>
                                {backjob.open ? (
                                    <Button icon="circle-check" size="sm" aria-label={`Mark done: ${backjob.type}, ${backjob.on}`} onClick={() => onFinish(backjob.id)}>
                                        Mark done
                                    </Button>
                                ) : (
                                    <Tone tone="success">Done</Tone>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

export default function Warranty() {
    const [params, setParams] = useSearchParams();
    const search = params.get('q') ?? '';
    const customer = params.get('customer');
    const settled = useDebounced(search, 400);
    const query = new URLSearchParams({ ...(settled ? { q: settled } : {}), ...(customer ? { customer } : {}) }).toString();
    const { data, error, loading, reload } = useResource<Results>(`/warranty${query ? `?${query}` : ''}`);
    const [logging, setLogging] = useState<Job | null>(null);
    const id = useId();

    const go = (key: 'q' | 'customer', value: string) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);

                if (value) {
                    next.set(key, value);
                } else {
                    next.delete(key);
                }

                return next;
            },
            { replace: true },
        );

    const finish = async (backjob: number) => {
        if (!(await confirm({ title: 'Mark done', description: 'The backjob is finished.', label: 'Confirm', icon: 'circle-check' }))) {
            return;
        }

        try {
            await api('POST', `/warranty/backjobs/${backjob}/done`);
            toast.success('Backjob done');
            document.getElementById(id)?.focus();
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        reload();
    };

    return (
        <>
            <PageHeader title="Warranty" />

            <div className="flex flex-wrap items-start gap-3">
                <div className="w-full max-w-xl">
                    <label htmlFor={id} className="sr-only">
                        Search for a finished job
                    </label>
                    <Input id={id} type="search" value={search} onChange={(event) => go('q', event.currentTarget.value)} placeholder="Plate, customer name, phone or booking reference" aria-describedby={`${id}-hint`} className={controlClass} />
                    <p id={`${id}-hint`} className="mt-1 text-xs text-muted-foreground">
                        Plate, customer name, phone or booking reference.
                    </p>
                </div>
                {data?.guest && (
                    <p className="flex flex-wrap items-center gap-2 text-sm">
                        <span>
                            Showing{' '}
                            <Link to={`/customers/${data.guest.id}`} className="font-semibold underline underline-offset-4">
                                {data.guest.name}
                            </Link>
                            ’s finished jobs
                        </span>
                        <Button icon="x" size="sm" onClick={() => go('customer', '')}>
                            Show all
                        </Button>
                    </p>
                )}
            </div>

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={3} />
            ) : (
                <div aria-busy={loading} className="space-y-4">
                    <p role="status" className="sr-only">
                        {data.searched ? `${data.data.length} finished ${data.data.length === 1 ? 'job' : 'jobs'} found.` : ''}
                    </p>
                    {!data.searched ? (
                        <EmptyState icon="shield-check" title="Search for a finished job to see its warranty." />
                    ) : data.data.length === 0 ? (
                        <EmptyState icon="search" title="No finished job found.">
                            Warranty starts on the day a unit exits, so a booking that hasn’t exited isn’t listed.
                        </EmptyState>
                    ) : (
                        data.data.map((job) => <Result key={job.id} job={job} onLog={() => setLogging(job)} onFinish={finish} />)
                    )}
                </div>
            )}

            <Modal open={logging !== null} onClose={() => setLogging(null)} title="Log a backjob" description={logging && `${logging.reference} · ${logging.name}`}>
                {logging && <BackjobForm key={logging.id} job={logging} onClose={() => setLogging(null)} onDone={reload} />}
            </Modal>
        </>
    );
}
