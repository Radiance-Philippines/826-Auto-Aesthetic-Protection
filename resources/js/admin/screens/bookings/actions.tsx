import { type FormEvent, type ReactNode, useState } from 'react';

import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button } from '../../ui/Button';
import { DateField } from '../../ui/DateField';
import { Modal, ModalActions } from '../../ui/Dialog';
import { SwitchField, TextField } from '../../ui/Field';
import type { IconName } from '../../ui/icons';
import { SelectField } from '../../ui/Select';
import { toast } from '../../ui/toast';
import { Alert, copy, useSubmit } from './parts';
import type { Detail } from './View';

export type DialogName = 'markPaid' | 'rejectProof' | 'setSize' | 'schedule' | 'assignedTo' | 'move' | 'readyDate' | 'link' | 'cancel';

type Props = {
    booking: Detail;
    onClose: () => void;
    /** After something changed */
    onDone: () => void;
    onWarning: (warning: { title: string; text: string } | null) => void;
};

/** A dialog's form: sends, shows the server's errors, closes when it worked */
function Form({ send, onClose, label, icon, destructive, children }: { send: () => Promise<void>; onClose: () => void; label: string; icon: IconName; destructive?: boolean; children: (errors: Record<string, string>) => ReactNode }) {
    const { errors, failed, busy, submit } = useSubmit();
    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        void submit(send);
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            {children(errors)}
            <ModalActions onCancel={onClose}>
                <Button type="submit" icon={icon} variant={destructive ? 'destructive' : 'primary'} busy={busy}>
                    {label}
                </Button>
            </ModalActions>
        </form>
    );
}

function MarkPaid({ booking, onClose, onDone }: Props) {
    const [note, setNote] = useState(booking.values.paymentNote ?? '');

    return (
        <Form
            label="Confirm"
            icon="banknote"
            onClose={onClose}
            send={async () => {
                await api('POST', `/bookings/${booking.id}/payment/approve`, { note });
                toast.success('Booking confirmed');
                onClose();
                onDone();
            }}
        >
            {(errors) => <TextField label="Payment note" hint="How you know it came in, like the reference number." error={errors.note} value={note} onValueChange={setNote} placeholder="GCash ref no." maxLength={255} autoComplete="off" />}
        </Form>
    );
}

function RejectProof({ booking, onClose, onDone }: Props) {
    const [reason, setReason] = useState('');

    return (
        <Form
            label="Ask again"
            icon="undo-2"
            onClose={onClose}
            send={async () => {
                await api('POST', `/bookings/${booking.id}/payment/reject`, { reason });
                toast.success('Asked for another screenshot');
                onClose();
                onDone();
            }}
        >
            {(errors) => (
                <TextField label="Why (the customer sees this)" hint="Say what is wrong with the screenshot." error={errors.reason} value={reason} onValueChange={setReason} placeholder="The amount doesn’t match, the screenshot is cut off…" required maxLength={255} autoComplete="off" />
            )}
        </Form>
    );
}

function SetSize({ booking, onClose, onDone, onWarning }: Props) {
    const [size, setSize] = useState(booking.values.vehicleSizeId);

    return (
        <Form
            label="Set size"
            icon="truck"
            onClose={onClose}
            send={async () => {
                const result = await api<{ missingPrices: string[]; money: { total: number | null } | null }>('POST', `/bookings/${booking.id}/size`, { vehicle_size_id: size });
                const name = booking.sizes.find((each) => each.value === size)?.label ?? '';

                if (result.missingPrices.length > 0) {
                    onWarning({ title: `No price for ${name} yet`, text: `Set ${result.missingPrices.join(', ')} for ${name} in Shop → Services. The booking stays in Needs you until it has a price.` });
                } else {
                    onWarning(null);
                    const total = result.money?.total;
                    toast.success(total === null || total === undefined ? `Size set: ${name}` : `Size set: ${name}. Price: ₱${total.toLocaleString('en-PH')}`);
                }

                onClose();
                onDone();
            }}
        >
            {(errors) => <SelectField label="Size class" hint="Pick it once you have seen the unit." error={errors.vehicle_size_id} options={booking.sizes} value={size} onChange={setSize} />}
        </Form>
    );
}

function Schedule({ booking, onClose, onDone }: Props) {
    const [visit, setVisit] = useState(booking.values.dropOffOn ?? booking.values.preferredDate ?? '');
    const [ready, setReady] = useState(booking.values.readyOn ?? '');
    const [askFee, setAskFee] = useState(booking.values.askFeeLabel !== null);

    return (
        <Form
            label="Schedule"
            icon="calendar"
            onClose={onClose}
            send={async () => {
                const saved = await api<Detail>('POST', `/bookings/${booking.id}/schedule`, { dropOffOn: visit || null, readyOn: ready || null, askFee: booking.values.askFeeLabel !== null && askFee });
                toast.success(`Scheduled: ${saved.when}`);
                onClose();
                onDone();
            }}
        >
            {(errors) => (
                <>
                    <DateField label="Team comes on" hint="The day you agreed with the customer." error={errors.dropOffOn} value={visit || null} onChange={setVisit} clearable />
                    <DateField label="Done by" hint="Leave blank to work it out from the services." error={errors.readyOn} value={ready || null} onChange={setReady} min={visit || undefined} clearable />
                    {booking.values.askFeeLabel && <SwitchField label={booking.values.askFeeLabel} hint="The customer pays it before the booking is confirmed." checked={askFee} onChange={setAskFee} />}
                </>
            )}
        </Form>
    );
}

function AssignedTo({ booking, onClose, onDone }: Props) {
    const [value, setValue] = useState(booking.assignedTo ?? '');

    return (
        <Form
            label="Save"
            icon="save"
            onClose={onClose}
            send={async () => {
                await api('POST', `/bookings/${booking.id}/assigned-to`, { assignedTo: value });
                toast.success('Saved');
                onClose();
                onDone();
            }}
        >
            {(errors) => <TextField label="Assigned to" hint="Type the name or names. Leave blank to clear." error={errors.assignedTo} value={value} onValueChange={setValue} placeholder="Jun, Ben" maxLength={120} autoComplete="off" />}
        </Form>
    );
}

function Move({ booking, onClose, onDone }: Props) {
    const [date, setDate] = useState(booking.values.dropOffOn ?? '');
    const [force, setForce] = useState(false);
    const { data } = useResource<{ blocked: string[]; canOverbook: boolean }>(`/bookings/${booking.id}/dates${date ? `?date=${date}` : ''}`);

    return (
        <Form
            label="Move"
            icon="calendar"
            onClose={onClose}
            send={async () => {
                const saved = await api<Detail>('POST', `/bookings/${booking.id}/reschedule`, { dropOffOn: date || null, force: force && !!data?.canOverbook });
                toast.success(`Moved: ${saved.when}`);
                onClose();
                onDone();
            }}
        >
            {(errors) => (
                <>
                    <DateField label="New drop-off date" hint={`Takes ${booking.values.takes}. The ready date moves with it.`} error={errors.dropOffOn} value={date || null} onChange={setDate} unavailable={data?.blocked} />
                    {data?.canOverbook && <SwitchField label="Book even if it’s full" hint="For when you’ve made room yourself." checked={force} onChange={setForce} />}
                </>
            )}
        </Form>
    );
}

function ReadyDate({ booking, onClose, onDone }: Props) {
    const [date, setDate] = useState(booking.values.readyOn ?? '');

    return (
        <Form
            label="Submit"
            icon="calendar"
            onClose={onClose}
            send={async () => {
                await api('POST', `/bookings/${booking.id}/ready-date`, { date: date || null });
                toast.success('Saved');
                onClose();
                onDone();
            }}
        >
            {(errors) => <DateField label="Ready around" hint="Not before the drop-off day." error={errors.date} value={date || null} onChange={setDate} min={booking.values.dropOffOn ?? undefined} />}
        </Form>
    );
}

function Cancel({ booking, onClose, onDone }: Props) {
    const [reason, setReason] = useState('');
    const label = booking.values.status === 'requested' ? 'Decline' : 'Cancel booking';

    return (
        <Form
            label={label}
            icon="x"
            destructive
            onClose={onClose}
            send={async () => {
                const saved = await api<Detail>('POST', `/bookings/${booking.id}/cancel`, { reason });
                toast.success(saved.status);
                onClose();
                onDone();
            }}
        >
            {(errors) => <TextField label="Reason (the customer sees this)" hint="Optional. Keep it short and kind." error={errors.reason} value={reason} onValueChange={setReason} maxLength={250} autoComplete="off" />}
        </Form>
    );
}

function Link({ booking, onClose }: Props) {
    return (
        <div className="space-y-4">
            <TextField label="Link" hint="Opens their booking without signing in. Keep it private." value={booking.link} readOnly onFocus={(event) => event.currentTarget.select()} />
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button icon="check" onClick={onClose}>
                    Done
                </Button>
                <Button icon="clipboard-check" variant="primary" onClick={() => copy(booking.link, 'Link')}>
                    Copy link
                </Button>
            </div>
        </div>
    );
}

const dialogs: Record<DialogName, { title: (booking: Detail) => string; description?: (booking: Detail) => string; body: (props: Props) => ReactNode }> = {
    markPaid: {
        title: (booking) => `Confirm ${booking.name}’s booking?`,
        description: (booking) => (booking.values.status === 'payment_review' ? 'Check the screenshot against your GCash or Maya first. The customer gets an email that they’re booked.' : 'For a fee paid another way (at the counter, by bank). The customer gets an email that they’re booked.'),
        body: MarkPaid,
    },
    rejectProof: {
        title: () => 'Ask for another screenshot',
        description: (booking) => `The customer sees your reason and has ${booking.values.holdMinutes} minutes to upload again before the slot opens up. Their screenshot is deleted.`,
        body: RejectProof,
    },
    setSize: {
        title: (booking) => `Review ${booking.vehicle ?? 'the unit'}`,
        description: () => 'Pick the size class once you have seen the unit. It sets the price from each service’s rates, and the customer gets an email with it. Their saved unit keeps the size, so next time it’s priced at once.',
        body: SetSize,
    },
    schedule: { title: () => 'Schedule', description: () => 'Agree on the day with the customer first. They get an email with it.', body: Schedule },
    assignedTo: { title: () => 'Who is on this unit?', body: AssignedTo },
    move: { title: () => 'Move', body: Move },
    readyDate: { title: () => 'Change ready date', body: ReadyDate },
    link: { title: () => 'Booking link', description: () => 'Text or message this to the customer. It opens their booking page, where they see the status and pay, without signing in. Keep it private.', body: Link },
    cancel: {
        title: (booking) => (booking.values.status === 'requested' ? 'Decline' : 'Cancel booking'),
        description: (booking) => (booking.values.paid ? 'The day opens up again. Sort out the reservation fee with the customer yourself.' : 'The day opens up again. The customer gets an email.'),
        body: Cancel,
    },
};

export function ActionDialog({ name, ...props }: Props & { name: DialogName }) {
    const { title, description, body: Body } = dialogs[name];

    return (
        <Modal open onClose={props.onClose} title={title(props.booking)} description={description?.(props.booking)}>
            <Body {...props} />
        </Modal>
    );
}
