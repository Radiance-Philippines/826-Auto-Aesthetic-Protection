import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@ui-sdk/react/collapsible';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useMe } from '../../lib/me';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { confirm } from '../../ui/Dialog';
import { Icon, type IconName } from '../../ui/icons';
import { ActionMenu, type MenuAction } from '../../ui/Menu';
import { PageHeader } from '../../ui/PageHeader';
import type { Option } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { toast } from '../../ui/toast';
import { ActionDialog, type DialogName } from './actions';
import { Alert, CopyButton, Details, Entry, Section, Tone } from './parts';

export type Detail = {
    id: number;
    title: string;
    reference: string;
    branchId: number;
    whenLabel: string;
    when: string;
    branch: string | null;
    assignedTo: string | null;
    kind: string;
    kindLabel: string;
    services: string[];
    price: string;
    needsSize: boolean;
    discountNote: string | null;
    takes: string;
    arrivedAt: string | null;
    exitedAt: string | null;
    message: string | null;
    fee: { amount: string; paidNote: string | null; method: string | null; methodNote: string | null; rejectedReason: string | null; proofUrl: string | null } | null;
    payment: { paid: string; paidNote: string | null; method: string | null; note: string | null } | null;
    home: { address: string; area: string | null; landmark: string | null; site: string } | null;
    history: string[];
    status: string;
    tone: string;
    holdNote: string | null;
    name: string;
    customerId: number | null;
    accountNote: string | null;
    phone: string;
    phoneUrl: string | null;
    smsUrl: string | null;
    email: string | null;
    mailUrl: string | null;
    vehicle: string | null;
    sizeName: string | null;
    adminNote: string | null;
    madeLabel: string;
    madeAt: string | null;
    movedNote: string | null;
    link: string;
    actions: string[];
    can: { edit: boolean; delete: boolean };
    values: {
        status: string;
        next: string | null;
        paymentNote: string | null;
        vehicleSizeId: string | null;
        dropOffOn: string | null;
        readyOn: string | null;
        preferredDate: string | null;
        paid: boolean;
        holdMinutes: number;
        askFeeLabel: string | null;
        takes: string;
    };
    sizes: Option[];
};

/** The queue steps: label, icon and what the confirmation says */
const steps: Record<string, { label: string; icon: IconName; says: (booking: Detail) => string }> = {
    markArrived: { label: 'Arrived', icon: 'arrow-right-to-line', says: () => 'The unit is at the branch. Its arrival time is recorded and the customer gets an email.' },
    startWork: { label: 'In progress', icon: 'wrench', says: (booking) => (booking.kind === 'home' ? 'The team is at the customer’s place and has started.' : 'The job has started.') },
    qualityCheck: { label: 'Quality check', icon: 'search-check', says: () => 'The work is done and is being checked.' },
    markReady: { label: 'Ready', icon: 'badge-check', says: () => 'The customer gets an email that their car is ready.' },
    markExited: { label: 'Exited', icon: 'circle-check-big', says: () => 'The unit has left the branch. Its exit time is recorded.' },
};

const dash = (value: string | null | undefined) => value || '—';

export function BookingView() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { reload: reloadMe } = useMe();
    const { data, error, reload } = useResource<Detail>(`/bookings/${id}`);
    const [dialog, setDialog] = useState<DialogName | null>(null);
    const [warning, setWarning] = useState<{ title: string; text: string } | null>(null);

    if (!data || String(data.id) !== id) {
        return (
            <>
                <PageHeader title="Booking" />
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={5} />}
                <Link to="/bookings" className={buttonClass()}>
                    <Icon name="arrow-left" />
                    Back to bookings
                </Link>
            </>
        );
    }

    const booking = data;
    const can = (action: string) => booking.actions.includes(action);
    const refresh = () => {
        reload();
        reloadMe();
    };

    const run = async (request: () => Promise<unknown>, done?: string) => {
        try {
            await request();

            if (done) {
                toast.success(done);
            }
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        refresh();
    };

    const step = async (action: string) => {
        const { label, icon, says } = steps[action];
        const name = action === 'markExited' && booking.kind === 'home' ? 'Done' : label;

        if (await confirm({ title: name, description: says(booking), label: 'Confirm', icon })) {
            await run(() => api('POST', `/bookings/${booking.id}/stage`, { to: booking.values.next }), `${booking.name}: ${name}`);
        }
    };

    const noShow = async () => {
        if (await confirm({ title: 'No-show', description: 'The customer didn’t come. The day no longer counts toward the cap.', label: 'Confirm', icon: 'ban' })) {
            await run(() => api('POST', `/bookings/${booking.id}/no-show`), 'Marked as no-show');
        }
    };

    const unread = async () => {
        try {
            await api('POST', `/bookings/${booking.id}/unread`);
            reloadMe();
            navigate('/bookings');
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    const remove = async () => {
        if (!(await confirm({ title: `Delete ${booking.reference}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/bookings/${booking.id}`);
            toast.success('Deleted');
            reloadMe();
            navigate('/bookings');
        } catch (failure) {
            toast.error(errorMessage(failure));
            refresh();
        }
    };

    const cancelLabel = booking.values.status === 'requested' ? 'Decline' : 'Cancel booking';
    const more: MenuAction[] = [
        ...(can('reschedule') ? [{ id: 'move', label: 'Move', icon: 'calendar' as const, onSelect: () => setDialog('move') }] : []),
        ...(can('changeReadyDate') ? [{ id: 'ready', label: 'Change ready date', icon: 'calendar' as const, onSelect: () => setDialog('readyDate') }] : []),
        ...(can('noShow') ? [{ id: 'noShow', label: 'No-show', icon: 'ban' as const, onSelect: noShow }] : []),
        { id: 'link', label: 'Copy booking link', icon: 'external-link', onSelect: () => setDialog('link') },
        ...(booking.mailUrl ? [{ id: 'email', label: 'Email', icon: 'at-sign' as const, onSelect: () => window.location.assign(booking.mailUrl!) }] : []),
        ...(booking.can.edit ? [{ id: 'edit', label: 'Edit details', icon: 'pencil' as const, onSelect: () => navigate('edit') }] : []),
        ...(can('cancelBooking') ? [{ id: 'cancel', label: cancelLabel, icon: 'x' as const, destructive: true, onSelect: () => setDialog('cancel') }] : []),
        { id: 'unread', label: 'Mark as unread', icon: 'circle', onSelect: unread },
        ...(booking.can.delete ? [{ id: 'delete', label: 'Delete', icon: 'trash-2' as const, destructive: true, onSelect: remove }] : []),
    ];

    const actions = (
        <>
            {can('markPaid') && (
                <Button icon="banknote" variant="primary" onClick={() => setDialog('markPaid')}>
                    {booking.values.status === 'payment_review' ? 'Payment checked: confirm' : 'Mark fee paid'}
                </Button>
            )}
            {can('rejectProof') && (
                <Button icon="undo-2" onClick={() => setDialog('rejectProof')}>
                    Ask for another screenshot
                </Button>
            )}
            {can('setSize') && (
                <Button icon="truck" variant={booking.needsSize ? 'primary' : 'outline'} onClick={() => setDialog('setSize')}>
                    {booking.sizeName ? 'Change size' : 'Review unit'}
                </Button>
            )}
            {can('scheduleHome') && (
                <Button icon="calendar" variant="primary" onClick={() => setDialog('schedule')}>
                    Schedule
                </Button>
            )}
            {Object.keys(steps)
                .filter(can)
                .map((action) => (
                    <Button key={action} icon={steps[action].icon} variant="primary" onClick={() => step(action)}>
                        {action === 'markExited' && booking.kind === 'home' ? 'Done' : steps[action].label}
                    </Button>
                ))}
            {can('assignedTo') && (
                <Button icon="user" onClick={() => setDialog('assignedTo')}>
                    Assigned to
                </Button>
            )}
            {booking.phoneUrl && (
                <a href={booking.phoneUrl} className={buttonClass()}>
                    <Icon name="smartphone" />
                    Call
                </a>
            )}
            {booking.smsUrl && (
                <a href={booking.smsUrl} className={buttonClass()}>
                    <Icon name="message-circle" />
                    Text
                </a>
            )}
            <ActionMenu label="More actions for this booking" variant="outline" actions={more} />
        </>
    );

    const link = 'underline underline-offset-4 break-all';

    return (
        <>
            <div>
                <Link to="/bookings" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline pointer-coarse:min-h-11">
                    <Icon name="arrow-left" />
                    Bookings
                </Link>
                <PageHeader title={booking.title} actions={actions} />
            </div>

            {error && <ErrorState message={error} onRetry={reload} />}
            {warning && (
                <div className="space-y-2">
                    <Alert tone="warning" title={warning.title}>
                        {warning.text}
                    </Alert>
                    <Button icon="x" size="sm" onClick={() => setWarning(null)}>
                        Dismiss
                    </Button>
                </div>
            )}

            <div className="grid items-start gap-4 lg:grid-cols-3">
                <div className="min-w-0 space-y-4 lg:col-span-2">
                    <Section title="When and where">
                        <Details>
                            <Entry label={booking.whenLabel} wide>
                                <span className="font-semibold">{booking.when}</span>
                            </Entry>
                            <Entry label="Branch">{booking.branch}</Entry>
                            <Entry label="Assigned to">{booking.assignedTo || 'No one yet'}</Entry>
                            <Entry label="Kind">
                                <Tone tone={booking.kind === 'home' ? 'info' : 'gray'}>{booking.kindLabel}</Tone>
                            </Entry>
                            <Entry label="Services" wide>
                                <ul className="m-0 list-disc ps-5">
                                    {booking.services.map((service, index) => (
                                        <li key={index}>{service}</li>
                                    ))}
                                </ul>
                            </Entry>
                            <Entry label="Price" note={booking.discountNote}>
                                {booking.needsSize ? <Tone tone="warning">{booking.price}</Tone> : booking.price}
                            </Entry>
                            <Entry label="Takes">{booking.takes}</Entry>
                            <Entry label="Arrived">{dash(booking.arrivedAt)}</Entry>
                            <Entry label="Exited">{dash(booking.exitedAt)}</Entry>
                            <Entry label="From the customer" wide>
                                <span className="whitespace-pre-line">{booking.message || 'No note'}</span>
                            </Entry>
                        </Details>
                    </Section>

                    {booking.fee && (
                        <Section title="Reservation fee">
                            <Details>
                                <Entry label="Fee" note={booking.fee.paidNote}>
                                    {booking.fee.amount}
                                </Entry>
                                <Entry label="Paid with" note={booking.fee.methodNote}>
                                    {booking.fee.method ?? 'Not yet'}
                                </Entry>
                                {booking.fee.rejectedReason && (
                                    <Entry label="Asked for another screenshot" wide>
                                        <span className="text-destructive">{booking.fee.rejectedReason}</span>
                                    </Entry>
                                )}
                                {booking.fee.proofUrl && (
                                    <Entry label="Screenshot" note="Opens full size in a new tab. Check it against your GCash or Maya app before marking it paid." wide>
                                        <a href={booking.fee.proofUrl} target="_blank" rel="noopener" className="inline-block max-w-full">
                                            <img src={booking.fee.proofUrl} alt={`Payment screenshot from ${booking.name}`} loading="lazy" className="max-h-[32rem] max-w-full rounded-lg border border-border" />
                                        </a>
                                    </Entry>
                                )}
                            </Details>
                        </Section>
                    )}

                    {booking.payment && (
                        <Section title="Payment">
                            <Details>
                                <Entry label="Paid" note={booking.payment.paidNote}>
                                    {booking.payment.paid}
                                </Entry>
                                <Entry label="Paid with" note={booking.payment.note}>
                                    {dash(booking.payment.method)}
                                </Entry>
                            </Details>
                        </Section>
                    )}

                    {booking.home && (
                        <Section title="Home service">
                            <Details>
                                <Entry label="Address" wide>
                                    {booking.home.address}
                                </Entry>
                                <Entry label="Area">{dash(booking.home.area)}</Entry>
                                <Entry label="Landmark">{dash(booking.home.landmark)}</Entry>
                                <Entry label="Water / power / shade">{booking.home.site}</Entry>
                            </Details>
                        </Section>
                    )}

                    <Collapsible defaultOpen className="block">
                        <section aria-labelledby="booking-history" className="rounded-xl border border-border bg-card p-4">
                            <h2 id="booking-history" className="m-0 text-sm font-semibold">
                                <CollapsibleTrigger className="flex w-full cursor-pointer items-center gap-2 border-0 bg-transparent p-0 font-semibold text-inherit pointer-coarse:min-h-11">
                                    <Icon name="chevron-down" />
                                    History
                                </CollapsibleTrigger>
                            </h2>
                            <CollapsiblePanel>
                                {booking.history.length === 0 ? (
                                    <p className="mt-3 text-sm text-muted-foreground">Nothing recorded yet. Moves and changes from now on show here.</p>
                                ) : (
                                    <ul className="m-0 mt-3 list-disc space-y-1 ps-5">
                                        {booking.history.map((line, index) => (
                                            <li key={index} className="break-words">
                                                {line}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CollapsiblePanel>
                        </section>
                    </Collapsible>
                </div>

                <Section title="Customer" className="min-w-0">
                    <dl className="m-0 grid gap-3">
                        <Entry label="Status" note={booking.holdNote}>
                            <Tone tone={booking.tone}>{booking.status}</Tone>
                        </Entry>
                        <Entry label="Reference">
                            <span className="inline-flex items-center gap-1">
                                {booking.reference}
                                <CopyButton text={booking.reference} what="Reference" />
                            </span>
                        </Entry>
                        <Entry label="Name" note={booking.accountNote}>
                            {booking.customerId ? (
                                <Link to={`/customers/${booking.customerId}`} className={link}>
                                    {booking.name}
                                </Link>
                            ) : (
                                booking.name
                            )}
                        </Entry>
                        <Entry label="Mobile">
                            <span className="inline-flex flex-wrap items-center gap-1">
                                {booking.phoneUrl ? (
                                    <a href={booking.phoneUrl} className={link}>
                                        {booking.phone}
                                    </a>
                                ) : (
                                    booking.phone
                                )}
                                <CopyButton text={booking.phone} what="Mobile" />
                            </span>
                        </Entry>
                        <Entry label="Email">
                            {booking.email ? (
                                <span className="inline-flex flex-wrap items-center gap-1">
                                    <a href={`mailto:${booking.email}`} className={link}>
                                        {booking.email}
                                    </a>
                                    <CopyButton text={booking.email} what="Email" />
                                </span>
                            ) : (
                                '—'
                            )}
                        </Entry>
                        <Entry label="Vehicle">{dash(booking.vehicle)}</Entry>
                        <Entry label="Size class">
                            <Tone tone={booking.sizeName ? 'gray' : 'warning'}>{booking.sizeName ?? 'Not reviewed yet'}</Tone>
                        </Entry>
                        <Entry label="Private note">
                            <span className="whitespace-pre-line">{dash(booking.adminNote)}</span>
                        </Entry>
                        <Entry label={booking.madeLabel} note={booking.movedNote}>
                            {booking.madeAt}
                        </Entry>
                    </dl>
                </Section>
            </div>

            <ActionDialog name={dialog} booking={booking} onClose={() => setDialog(null)} onDone={refresh} onWarning={setWarning} />
        </>
    );
}
