import { type FormEvent, type ReactNode, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api, ApiError, errorMessage } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { type Column, DataTable, useClientTable } from '../../ui/DataTable';
import { confirm, Modal, ModalActions } from '../../ui/Dialog';
import { TextField } from '../../ui/Field';
import { Icon, type IconName } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { TabBar } from '../../ui/Tabs';
import { toast } from '../../ui/toast';
import { Alert, CopyButton, Details, Entry, Section, Tone, useSubmit } from '../bookings/parts';

type Unit = { id: number; year: number | null; model: string; type: string | null; plate: string; size: string | null; sizeId: string | null; reviewed: boolean; jobs: number; lastService: string | null; lastServiceAt: string };
type CustomerBooking = { id: number; reference: string; when: string; services: string; branch: string | null; status: string; tone: string; price: string };
type Referral = { id: number; usedBy: string | null; reference: string | null; bookingId: number | null; status: string; tone: string; discount: string; points: number | null };
type Points = { id: string; date: string; what: string; points: number; pesos: string | null; by: string | null };
type Job = { id: number; reference: string; date: string; branch: string; services: string; status: string; price: string };

type Customer = {
    id: number;
    name: string;
    phone: string | null;
    phoneUrl: string | null;
    email: string | null;
    branch: string | null;
    salesRep: string | null;
    source: string;
    notes: string | null;
    referral: { code: string | null; timesUsed: number; points: number; pointsNote: string };
    warranty: string;
    can: { edit: boolean; delete: boolean; warranty: boolean };
    chatId: number | null;
    redeem: { balance: number; description: string; bookings: Option[] | null } | null;
    units: Unit[];
    setSize: Option[] | null;
    bookings: CustomerBooking[] | null;
    referrals: Referral[];
    ledger: Points[];
};

const link = 'underline underline-offset-4 break-all';
const dash = (value: string | number | null | undefined) => (value === null || value === undefined || value === '' ? '—' : value);

/** One of the customer's lists: sorted and paged in the browser */
function Related<Row>({ caption, rows, columns, rowId, rowName, actions, empty, icon }: { caption: string; rows: Row[]; columns: Column<Row>[]; rowId: (row: Row) => string; rowName: (row: Row) => string; actions?: (row: Row) => ReactNode; empty: string; icon: IconName }) {
    const table = useClientTable(rows, columns, { pageSize: 25 });

    return <DataTable {...table} caption={caption} columns={columns} rowId={rowId} rowName={rowName} actions={actions} empty={<EmptyState icon={icon} title={empty} />} />;
}

function RedeemForm({ customer, redeem, onClose, onDone }: { customer: Customer; redeem: NonNullable<Customer['redeem']>; onClose: () => void; onDone: () => void }) {
    const [points, setPoints] = useState('');
    const [booking, setBooking] = useState('none');
    const [note, setNote] = useState('');
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const done = await api<{ points: number; off: string | null }>('POST', `/customers/${customer.id}/redeem`, { points: points || null, bookingId: redeem.bookings && booking !== 'none' ? Number(booking) : null, note });
            toast.success(`${done.points} points redeemed${done.off ? `: ${done.off}` : ''}`);
            onClose();
            onDone();
        });
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {(failed || errors.points || errors.note || errors.bookingId) && <Alert title="Not redeemed">{failed ?? errors.points ?? errors.note ?? errors.bookingId}</Alert>}
            <TextField label="Points" hint={`From 1 to ${Math.max(1, redeem.balance)}.`} error={errors.points} value={points} onValueChange={setPoints} inputMode="numeric" required autoComplete="off" />
            {redeem.bookings && <SelectField label="Take it off this booking" hint="Only open bookings with a price." error={errors.bookingId} options={[{ value: 'none', label: 'No booking' }, ...redeem.bookings]} value={booking} onChange={setBooking} />}
            <TextField label="Note" hint="Required when no booking is picked." error={errors.note} value={note} onValueChange={setNote} maxLength={190} autoComplete="off" />
            <ModalActions onCancel={onClose}>
                <Button type="submit" icon="gift" variant="primary" busy={busy}>
                    Redeem
                </Button>
            </ModalActions>
        </form>
    );
}

function SizeForm({ customer, unit, sizes, onClose, onDone }: { customer: Customer; unit: Unit; sizes: Option[]; onClose: () => void; onDone: () => void }) {
    const [size, setSize] = useState(unit.sizeId);
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const done = await api<{ size: string; priced: number }>('POST', `/customers/${customer.id}/units/${unit.id}/size`, { vehicleSizeId: size ? Number(size) : null });
            toast.success(`Size set: ${done.size}${done.priced > 0 ? `. ${done.priced} booking(s) priced.` : ''}`);
            onClose();
            onDone();
        });
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            <SelectField label="Size class" hint="Pick it once you have seen the unit." error={errors.vehicleSizeId} options={sizes} value={size} onChange={setSize} />
            <ModalActions onCancel={onClose}>
                <Button type="submit" icon="truck" variant="primary" busy={busy}>
                    Set size
                </Button>
            </ModalActions>
        </form>
    );
}

function History({ customer, unit }: { customer: Customer; unit: Unit }) {
    const { data, error, reload } = useResource<{ data: Job[] }>(`/customers/${customer.id}/units/${unit.id}`);

    if (!data) {
        return error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={3} />;
    }

    if (data.data.length === 0) {
        return <p className="text-sm text-muted-foreground">No bookings for this unit at your branches.</p>;
    }

    return (
        <ul className="m-0 list-none divide-y divide-border p-0 text-sm">
            {data.data.map((job) => (
                <li key={job.id} className="py-2">
                    <p className="flex flex-wrap justify-between gap-2">
                        <Link to={`/bookings/${job.id}`} className="font-medium underline underline-offset-4">
                            {job.reference}
                        </Link>
                        <span className="tabular-nums">{job.price}</span>
                    </p>
                    <p className="break-words">{job.services}</p>
                    <p className="text-xs break-words text-muted-foreground">
                        {job.date} · {job.branch} · {job.status}
                    </p>
                </li>
            ))}
        </ul>
    );
}

type Open = { kind: 'redeem' } | { kind: 'size' | 'history'; unit: Unit } | null;

function Show({ customer, reload }: { customer: Customer; reload: () => void }) {
    const navigate = useNavigate();
    const [tab, setTab] = useState('units');
    const [open, setOpen] = useState<Open>(null);
    const [kept, setKept] = useState<string | null>(null);

    const remove = async () => {
        if (!(await confirm({ title: `Delete ${customer.name}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/customers/${customer.id}`);
            toast.success('Deleted');
            navigate('/customers');
        } catch (failure) {
            const reason = failure instanceof ApiError ? failure.errors.customer?.[0] : null;

            if (reason) {
                setKept(reason);
            } else {
                toast.error(errorMessage(failure));
            }
        }
    };

    const actions = (
        <>
            {customer.can.edit && (
                <Link to="edit" className={buttonClass('primary')}>
                    <Icon name="pencil" />
                    Edit
                </Link>
            )}
            {customer.redeem && (
                <Button icon="gift" onClick={() => setOpen({ kind: 'redeem' })}>
                    Redeem points
                </Button>
            )}
            {customer.can.warranty && (
                <Link to={`/warranty?customer=${customer.id}`} className={buttonClass()}>
                    <Icon name="shield-check" />
                    Warranty
                </Link>
            )}
            {customer.chatId !== null && (
                <Link to={`/inbox?conversation=${customer.chatId}`} className={buttonClass()}>
                    <Icon name="message-circle" />
                    Messenger chat
                </Link>
            )}
            {customer.can.delete && (
                <Button icon="trash-2" variant="destructive" onClick={remove}>
                    Delete
                </Button>
            )}
        </>
    );

    const units: Column<Unit>[] = [
        { id: 'year', header: 'Year', cell: (unit) => dash(unit.year) },
        { id: 'model', header: 'Make and model', cell: (unit) => unit.model },
        { id: 'type', header: 'Type', cell: (unit) => dash(unit.type) },
        { id: 'plate', header: 'Plate', primary: true, cell: (unit) => unit.plate },
        { id: 'size', header: 'Size class', cell: (unit) => <Tone tone={unit.reviewed ? 'gray' : 'warning'}>{unit.size ?? 'Not reviewed'}</Tone> },
        { id: 'jobs', header: 'Jobs', align: 'center', sortValue: (unit) => unit.jobs, cell: (unit) => <span title="Bookings for this unit at your branches, whatever their status.">{unit.jobs}</span> },
        { id: 'last', header: 'Last service', sortValue: (unit) => unit.lastServiceAt, cell: (unit) => <span title="The day its latest finished job exited.">{unit.lastService ?? 'Not yet'}</span> },
    ];

    const bookings: Column<CustomerBooking>[] = [
        {
            id: 'reference',
            header: 'Reference',
            primary: true,
            cell: (booking) => (
                <>
                    <Link to={`/bookings/${booking.id}`} className="underline underline-offset-4">
                        {booking.reference}
                    </Link>
                    <span className="block text-xs font-normal text-muted-foreground">{booking.when}</span>
                </>
            ),
        },
        { id: 'services', header: 'Services', cell: (booking) => booking.services },
        { id: 'branch', header: 'Branch', cell: (booking) => booking.branch },
        { id: 'status', header: 'Status', cell: (booking) => <Tone tone={booking.tone}>{booking.status}</Tone> },
        { id: 'price', header: 'Price', cell: (booking) => <span className="tabular-nums">{booking.price}</span> },
    ];

    const referrals: Column<Referral>[] = [
        { id: 'usedBy', header: 'Used by', primary: true, cell: (referral) => dash(referral.usedBy) },
        {
            id: 'booking',
            header: 'Booking',
            cell: (referral) =>
                referral.bookingId ? (
                    <Link to={`/bookings/${referral.bookingId}`} className="underline underline-offset-4">
                        {referral.reference}
                    </Link>
                ) : (
                    dash(referral.reference)
                ),
        },
        { id: 'status', header: 'Status', cell: (referral) => <Tone tone={referral.tone}>{referral.status}</Tone> },
        { id: 'discount', header: 'Discount', cell: (referral) => referral.discount },
        { id: 'points', header: 'Points', cell: (referral) => dash(referral.points) },
    ];

    const ledger: Column<Points>[] = [
        { id: 'date', header: 'Date', cell: (line) => line.date },
        { id: 'what', header: 'What', primary: true, cell: (line) => <span className="break-words">{line.what}</span> },
        { id: 'points', header: 'Points', cell: (line) => <Tone tone={line.points > 0 ? 'success' : 'warning'}>{line.points > 0 ? `+${line.points}` : line.points}</Tone> },
        { id: 'pesos', header: 'Pesos off', cell: (line) => line.pesos ?? '—' },
        { id: 'by', header: 'By', cell: (line) => line.by ?? '—' },
    ];

    const tabs = [
        { value: 'units', label: 'Units', icon: 'truck' as const },
        ...(customer.bookings ? [{ value: 'bookings', label: 'Bookings', icon: 'inbox' as const }] : []),
        { value: 'referral', label: 'Referral', icon: 'gift' as const },
        { value: 'points', label: 'Points', icon: 'wallet' as const },
    ];

    return (
        <>
            <div>
                <Link to="/customers" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline pointer-coarse:min-h-11">
                    <Icon name="arrow-left" />
                    Customers
                </Link>
                <PageHeader title={customer.name} actions={actions} />
            </div>

            {kept && (
                <div className="space-y-2">
                    <Alert title="Not deleted">{kept}</Alert>
                    <Button icon="x" size="sm" onClick={() => setKept(null)}>
                        Dismiss
                    </Button>
                </div>
            )}

            <div className="grid items-start gap-4 lg:grid-cols-3">
                <Section title="Details" className="min-w-0 lg:col-span-2">
                    <Details>
                        <Entry label="Name">
                            <span className="font-semibold">{customer.name}</span>
                        </Entry>
                        <Entry label="Mobile">
                            {customer.phone ? (
                                <span className="inline-flex flex-wrap items-center gap-1">
                                    {customer.phoneUrl ? (
                                        <a href={customer.phoneUrl} className={link}>
                                            {customer.phone}
                                        </a>
                                    ) : (
                                        customer.phone
                                    )}
                                    <CopyButton text={customer.phone} what="Mobile" />
                                </span>
                            ) : (
                                '—'
                            )}
                        </Entry>
                        <Entry label="Email">
                            {customer.email ? (
                                <span className="inline-flex flex-wrap items-center gap-1">
                                    <span className="break-all">{customer.email}</span>
                                    <CopyButton text={customer.email} what="Email" />
                                </span>
                            ) : (
                                '—'
                            )}
                        </Entry>
                        <Entry label="Home branch">{dash(customer.branch)}</Entry>
                        <Entry label="Sales rep">{dash(customer.salesRep)}</Entry>
                        <Entry label="Came in by">{customer.source}</Entry>
                        <Entry label="Private note" wide>
                            <span className="whitespace-pre-line">{dash(customer.notes)}</span>
                        </Entry>
                    </Details>
                </Section>
                <Section title="Referral" className="min-w-0">
                    <dl className="m-0 grid gap-3">
                        <Entry label="Code">
                            {customer.referral.code ? (
                                <span className="inline-flex items-center gap-1">
                                    <span className="break-all">{customer.referral.code}</span>
                                    <CopyButton text={customer.referral.code} what="Code" />
                                </span>
                            ) : (
                                'Not made yet'
                            )}
                        </Entry>
                        <Entry label="Times used">{customer.referral.timesUsed}</Entry>
                        <Entry label="Points balance" note={customer.referral.pointsNote}>
                            {customer.referral.points}
                        </Entry>
                    </dl>
                </Section>
                <Section title="Warranty" className="min-w-0 lg:col-span-3">
                    <dl className="m-0">
                        <Entry label="Services under warranty" note="Open the warranty checker for the dates and to log a backjob.">
                            {customer.can.warranty ? (
                                <Link to={`/warranty?customer=${customer.id}`} className="underline underline-offset-4">
                                    {customer.warranty}
                                </Link>
                            ) : (
                                customer.warranty
                            )}
                        </Entry>
                    </dl>
                </Section>
            </div>

            <TabBar label="This customer’s records" tabs={tabs} value={tab} onChange={setTab}>
                {tab === 'units' && (
                    <Related
                        key="units"
                        caption="Units"
                        rows={customer.units}
                        columns={units}
                        rowId={(unit) => String(unit.id)}
                        rowName={(unit) => unit.plate}
                        empty="No units"
                        icon="truck"
                        actions={(unit) => (
                            <>
                                <Button icon="clipboard-check" size="sm" aria-label={`History of ${unit.plate}`} onClick={() => setOpen({ kind: 'history', unit })}>
                                    History
                                </Button>
                                {customer.setSize && (
                                    <Button icon="truck" size="sm" aria-label={`Set size of ${unit.plate}`} onClick={() => setOpen({ kind: 'size', unit })}>
                                        Set size
                                    </Button>
                                )}
                            </>
                        )}
                    />
                )}
                {tab === 'bookings' && customer.bookings && <Related key="bookings" caption="Bookings" rows={customer.bookings} columns={bookings} rowId={(each) => String(each.id)} rowName={(each) => each.reference} empty="No bookings" icon="inbox" />}
                {tab === 'referral' && <Related key="referral" caption="Referral" rows={customer.referrals} columns={referrals} rowId={(each) => String(each.id)} rowName={(each) => each.usedBy ?? 'Friend'} empty="No one has used this code yet" icon="gift" />}
                {tab === 'points' && <Related key="points" caption="Points" rows={customer.ledger} columns={ledger} rowId={(each) => each.id} rowName={(each) => each.what} empty="No points earned or spent yet" icon="wallet" />}
            </TabBar>

            {customer.redeem && (
                <Modal open={open?.kind === 'redeem'} onClose={() => setOpen(null)} title={`Redeem points: ${customer.redeem.balance} available`} description={customer.redeem.description}>
                    {open?.kind === 'redeem' && <RedeemForm customer={customer} redeem={customer.redeem} onClose={() => setOpen(null)} onDone={reload} />}
                </Modal>
            )}
            {open?.kind === 'size' && customer.setSize && (
                <Modal open onClose={() => setOpen(null)} title="Set size" description="Sets the unit’s size class. Its open bookings that had no price yet are priced from the services’ rates, and the customer gets an email with it.">
                    <SizeForm customer={customer} unit={open.unit} sizes={customer.setSize} onClose={() => setOpen(null)} onDone={reload} />
                </Modal>
            )}
            {open?.kind === 'history' && (
                <Modal open onClose={() => setOpen(null)} title={`Service history: ${open.unit.plate}`} description={`${open.unit.year ?? ''} ${open.unit.model}`.trim()}>
                    <History customer={customer} unit={open.unit} />
                    <div className="mt-4 flex justify-end">
                        <Button icon="x" onClick={() => setOpen(null)}>
                            Close
                        </Button>
                    </div>
                </Modal>
            )}
        </>
    );
}

export function CustomerView() {
    const { id } = useParams();
    const { data, error, reload } = useResource<Customer>(`/customers/${id}`);

    if (!data || String(data.id) !== id) {
        return (
            <>
                <PageHeader title="Customer" />
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={5} />}
                <Link to="/customers" className={buttonClass()}>
                    <Icon name="arrow-left" />
                    Back to customers
                </Link>
            </>
        );
    }

    return <Show key={data.id} customer={data} reload={reload} />;
}
