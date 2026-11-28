import { type FormEvent, useEffect, useRef, useState } from 'react';

import { api } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { SwitchField, TextField } from '../ui/Field';
import { toFormData } from '../ui/FileUpload';
import { PageHeader } from '../ui/PageHeader';
import { ErrorState, Loading } from '../ui/states';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Alert, Section, useSubmit } from './bookings/parts';
import { AccountFields } from './branches/AccountFields';

type Method = 'gcash' | 'maya';

type Values = {
    reservationFeeEnabled: boolean;
    amountValue: number | string | null;
    holdMinutes: number | string;
    instructions: string | null;
    gcashEnabled: boolean;
    gcashName: string | null;
    gcashNumber: string | null;
    mayaEnabled: boolean;
    mayaName: string | null;
    mayaNumber: string | null;
};

type Saved = { values: Values; gcashQrUrl: string | null; mayaQrUrl: string | null };

function Settings({ record, onSaved }: { record: Saved; onSaved: (record: Saved) => void }) {
    const [values, setValues] = useState(record.values);
    const [qr, setQr] = useState<Record<Method, File[]>>({ gcash: [], maya: [] });
    const [removed, setRemoved] = useState<Record<Method, boolean>>({ gcash: false, maya: false });
    const { errors, failed, busy, submit } = useSubmit();
    const form = useRef<HTMLFormElement>(null);

    // Filament's shortcut for Save
    useEffect(() => {
        const save = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                form.current?.requestSubmit();
            }
        };

        window.addEventListener('keydown', save);

        return () => window.removeEventListener('keydown', save);
    }, []);

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        const body = { ...values, removegcashQr: removed.gcash, removemayaQr: removed.maya, gcashQr: qr.gcash[0], mayaQr: qr.maya[0] };

        void submit(async () => {
            const saved = await api<Saved>('POST', '/payment-settings', qr.gcash.length || qr.maya.length ? toFormData(body) : body);
            toast.success('Payments saved');
            onSaved(saved);
        });
    };

    const account = (method: Method, label: string) => (
        <Section title={label} className="min-w-0">
            <AccountFields
                label={label}
                toggle={{ label: `Accept ${label}`, hint: 'Customers only see it when it’s on and has a QR code or a number. A branch with its own account uses that instead.' }}
                qrHint={`Your ${label} QR (from the app: “Receive” or “QR”), PNG, JPG or WebP up to 5 MB. Kept exactly as uploaded so it scans.`}
                account={{ enabled: values[`${method}Enabled`], name: values[`${method}Name`], number: values[`${method}Number`] }}
                onChange={(changes) => setValues((current) => ({ ...current, ...(Object.fromEntries(Object.entries(changes).map(([key, value]) => [`${method}${key[0].toUpperCase()}${key.slice(1)}`, value])) as Partial<Values>) }))}
                files={qr[method]}
                onFiles={(files) => setQr((current) => ({ ...current, [method]: files }))}
                qrUrl={removed[method] ? null : record[`${method}QrUrl`]}
                onRemoveQr={() => setRemoved((current) => ({ ...current, [method]: true }))}
                errors={{ qr: errors[`${method}Qr`], name: errors[`${method}Name`], number: errors[`${method}Number`] }}
            />
        </Section>
    );

    return (
        <form ref={form} onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            {Object.keys(errors).length > 0 && !failed && <Alert title="Not saved">Check the fields marked below.</Alert>}

            <Section title="Reservation fee">
                <p className="mt-0 mb-4 text-sm text-muted-foreground">
                    Off: a reservation is confirmed the moment it’s made. On: the day is held while the customer pays by GCash or Maya and uploads a screenshot; you check it and confirm. Nothing is charged on the site.
                </p>
                <div className="space-y-4">
                    <SwitchField label="Ask for a reservation fee" hint="Customers pay in their own app and upload a screenshot." checked={values.reservationFeeEnabled} onChange={(reservationFeeEnabled) => setValues((current) => ({ ...current, reservationFeeEnabled }))} />
                    {values.reservationFeeEnabled && (
                        <div className="grid items-start gap-4 sm:grid-cols-2">
                            <TextField label="Amount (₱)" hint="A fixed amount: there is no price yet when a customer books. Bookings already made keep their fee." inputMode="numeric" error={errors.amountValue} value={String(values.amountValue ?? '')} onValueChange={(amountValue) => setValues((current) => ({ ...current, amountValue }))} required autoComplete="off" />
                            <TextField label="Hold the slot for (minutes)" hint="If no screenshot comes in by then, the day opens up for others." inputMode="numeric" error={errors.holdMinutes} value={String(values.holdMinutes ?? '')} onValueChange={(holdMinutes) => setValues((current) => ({ ...current, holdMinutes }))} required autoComplete="off" />
                            <TextAreaField label="Payment notes for customers" hint="Optional. Shown under the QR codes." placeholder="Put your booking reference in the message" error={errors.instructions} value={values.instructions ?? ''} onValueChange={(instructions) => setValues((current) => ({ ...current, instructions }))} rows={3} maxLength={1000} className="sm:col-span-2" />
                        </div>
                    )}
                </div>
            </Section>

            <div className="grid items-start gap-4 lg:grid-cols-2">
                {account('gcash', 'GCash')}
                {account('maya', 'Maya')}
            </div>

            <Button type="submit" icon="save" variant="primary" busy={busy}>
                Save payments
            </Button>
        </form>
    );
}

export default function PaymentSettings() {
    const { data, error, reload } = useResource<Saved>('/payment-settings');
    /** What the last save answered; the form starts again from it */
    const [saved, setSaved] = useState<{ record: Saved; round: number } | null>(null);
    const record = saved?.record ?? data;

    return (
        <>
            <PageHeader title="Payments" description="The reservation fee and the main GCash and Maya accounts." />
            {error && <ErrorState message={error} onRetry={reload} />}
            {record ? <Settings key={saved?.round ?? 0} record={record} onSaved={(next) => setSaved({ record: next, round: (saved?.round ?? 0) + 1 })} /> : !error && <Loading rows={5} />}
        </>
    );
}
