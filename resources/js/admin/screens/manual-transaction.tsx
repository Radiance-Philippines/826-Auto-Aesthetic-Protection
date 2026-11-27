import { type FormEvent, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { api, errorMessage } from '../lib/api';
import { useMe } from '../lib/me';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { DateField } from '../ui/DateField';
import { SwitchField, TextField } from '../ui/Field';
import { PageHeader } from '../ui/PageHeader';
import { type Option, SelectField } from '../ui/Select';
import { ErrorState, Loading } from '../ui/states';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Alert, CheckboxListField, Section, useSubmit } from './bookings/parts';
import { SearchField } from './bookings/SearchField';

type Options = {
    branches: Option[];
    services: Option[];
    statuses: Option[];
    methods: Option[];
    types: Option[];
    sizes: Option[];
    maxYear: number;
    today: string;
    referralHint: string | null;
};

type Rate = { id: number; name: string; price: number | null };

const grid = 'grid items-start gap-4 sm:grid-cols-2';

export default function ManualTransaction() {
    const navigate = useNavigate();
    const { reload: reloadMe } = useMe();
    const [branch, setBranch] = useState<string | null>(null);
    const [newCustomer, setNewCustomer] = useState(false);
    const [guest, setGuest] = useState<Option | null>(null);
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [date, setDate] = useState<string | null>(null);
    const [status, setStatus] = useState('exited');
    const [year, setYear] = useState('');
    const [model, setModel] = useState('');
    const [type, setType] = useState<string | null>(null);
    const [plate, setPlate] = useState('');
    const [size, setSize] = useState<string | null>(null);
    const [services, setServices] = useState<string[]>([]);
    const [rates, setRates] = useState<Rate[]>([]);
    const [prices, setPrices] = useState<Record<string, string>>({});
    const [discount, setDiscount] = useState('');
    const [referral, setReferral] = useState('');
    const [paid, setPaid] = useState('');
    const [method, setMethod] = useState('none');
    const [reference, setReference] = useState('');
    const [note, setNote] = useState('');
    const { errors, failed, busy, submit } = useSubmit();
    const form = useRef<HTMLFormElement>(null);

    const { data, error, reload } = useResource<Options>(`/manual-transaction${branch ? `?branch=${branch}` : ''}`);

    // One branch: it is chosen already
    if (data && !branch && data.branches.length === 1) {
        setBranch(data.branches[0].value);
    }

    // Each picked service's price starts from its rate for the size class, again whenever either changes
    const asked = services.map((id) => `services[]=${id}`).join('&');

    useEffect(() => {
        if (!asked) {
            setRates([]);
            setPrices({});

            return;
        }

        const controller = new AbortController();

        api<{ data: Rate[] }>('GET', `/manual-transaction/rates?${asked}${size ? `&size=${size}` : ''}`, undefined, controller.signal).then(
            (answer) => {
                setRates(answer.data);
                setPrices(Object.fromEntries(answer.data.map((rate) => [String(rate.id), rate.price === null ? '' : String(rate.price)])));
            },
            (failure: unknown) => {
                if (!controller.signal.aborted) {
                    toast.error(errorMessage(failure));
                }
            },
        );

        return () => controller.abort();
    }, [asked, size]);

    // Ctrl or Cmd + S saves, as on the Filament page
    useEffect(() => {
        const save = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                form.current?.requestSubmit();
            }
        };
        window.addEventListener('keydown', save);

        return () => window.removeEventListener('keydown', save);
    }, []);

    if (!data) {
        return (
            <>
                <PageHeader title="Manual transaction" />
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={6} />}
            </>
        );
    }

    const day = date ?? data.today;

    const save = (event: FormEvent) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        void submit(async () => {
            const number = (value: string) => (value.trim() === '' ? null : value.trim());
            const saved = await api<{ id: number; message: string }>('POST', '/manual-transaction', {
                branchId: branch ? Number(branch) : null,
                newCustomer,
                guestId: !newCustomer && guest ? Number(guest.value) : null,
                name: newCustomer ? name : null,
                phone: newCustomer ? phone : null,
                serviceDate: day || null,
                status,
                vehicleYear: number(year),
                vehicleModel: model,
                vehicleType: type,
                plateNumber: plate,
                vehicleSizeId: size ? Number(size) : null,
                services: services.map(Number),
                prices: Object.fromEntries(services.map((id) => [id, number(prices[id] ?? '')])),
                discount: number(discount),
                referralCode: data.referralHint ? referral : null,
                amountPaid: number(paid),
                paymentMethod: method === 'none' ? null : method,
                paymentReference: reference,
                adminNote: note,
            });
            toast.success(`Saved: ${saved.message}`);
            reloadMe();
            navigate(`/bookings/${saved.id}`);
        });
    };

    const priceError = Object.keys(errors).find((key) => key.startsWith('prices.'));

    return (
        <>
            <PageHeader title="Manual transaction" description="For a walk-in or phone job done outside the site, even one already finished. The customer gets no email." />

            <form ref={form} onSubmit={save} noValidate className="space-y-4">
                {failed && <Alert title="Not saved">{failed}</Alert>}
                {errors.transaction && <Alert title="Not saved">{errors.transaction}</Alert>}
                {Object.keys(errors).length > 0 && !errors.transaction && <Alert title="Not saved">Check the fields marked below.</Alert>}

                <div className="grid items-start gap-4 lg:grid-cols-3">
                    <Section title="Customer" className="min-w-0 lg:col-span-2">
                        <div className={grid}>
                            <SelectField
                                label="Branch"
                                hint="Where the job was done."
                                error={errors.branchId}
                                options={data.branches}
                                value={branch}
                                onChange={(next) => {
                                    setBranch(next);
                                    setServices([]);
                                }}
                            />
                            <SwitchField label="New customer" hint="Turn on to type their name and mobile." checked={newCustomer} onChange={setNewCustomer} />
                            {newCustomer ? (
                                <>
                                    <TextField key="name" label="Name" hint="Their full name." error={errors.name} value={name} onValueChange={setName} required maxLength={120} autoComplete="off" />
                                    <TextField key="phone" label="Mobile" hint="The same number is the same customer: an existing one is reused." type="tel" error={errors.phone} value={phone} onValueChange={setPhone} required maxLength={40} autoComplete="off" />
                                </>
                            ) : (
                                <div className="sm:col-span-2">
                                    <SearchField label="Customer" hint="Search by name, mobile or email." error={errors.guestId} path="/manual-transaction/customers" value={guest} onChange={setGuest} />
                                </div>
                            )}
                        </div>
                    </Section>

                    <Section title="Date and status" className="min-w-0">
                        <div className="space-y-4">
                            <DateField label="Date of service" hint="The day the job was done." error={errors.serviceDate} value={day || null} onChange={setDate} clearable />
                            <SelectField label="Status" hint="Where the unit is now." error={errors.status} options={data.statuses} value={status} onChange={setStatus} />
                        </div>
                    </Section>

                    <Section title="Unit" className="min-w-0 lg:col-span-2">
                        <div className={grid}>
                            <TextField label="Year" hint={`Between 1950 and ${data.maxYear}.`} error={errors.vehicleYear} value={year} onValueChange={setYear} inputMode="numeric" maxLength={4} autoComplete="off" />
                            <TextField label="Make and model" hint="Like Toyota Vios." error={errors.vehicleModel} value={model} onValueChange={setModel} placeholder="Toyota Vios" maxLength={80} autoComplete="off" />
                            <SelectField label="Type" hint="The body style." error={errors.vehicleType} options={data.types} value={type} onChange={setType} />
                            <TextField label="Plate number" hint="Letters and numbers; spaces are dropped." error={errors.plateNumber} value={plate} onValueChange={setPlate} maxLength={15} autoComplete="off" />
                            <SelectField label="Size class" hint="It sets the starting prices." error={errors.vehicleSizeId} options={data.sizes} value={size} onChange={setSize} />
                        </div>
                    </Section>

                    <Section title="Services" className="min-w-0 lg:col-span-3">
                        <CheckboxListField label="Services done" hint={branch ? 'The services this branch offers.' : 'Pick the branch first.'} error={errors.services ?? errors['services.0']} options={data.services} value={services} onChange={setServices} columns />
                    </Section>

                    {rates.length > 0 && (
                        <Section title="Prices" className="min-w-0 lg:col-span-3">
                            <p className="mb-3 text-sm text-muted-foreground">Start from the rates for the unit’s size class. Type over any price.</p>
                            <div className="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                {rates.map((rate) => (
                                    <TextField
                                        key={rate.id}
                                        label={rate.name}
                                        hint="In pesos, no centavos."
                                        error={errors[`prices.${rate.id}`]}
                                        value={prices[rate.id] ?? ''}
                                        onValueChange={(value) => setPrices((current) => ({ ...current, [rate.id]: value }))}
                                        inputMode="numeric"
                                        required
                                        autoComplete="off"
                                    />
                                ))}
                            </div>
                            {priceError && (
                                <p role="alert" className="sr-only">
                                    {errors[priceError]}
                                </p>
                            )}
                        </Section>
                    )}

                    <Section title="Payment" className="min-w-0 lg:col-span-2">
                        <div className={grid}>
                            <TextField label="Discount (optional)" hint="In pesos, taken off the total." error={errors.discount} value={discount} onValueChange={setDiscount} inputMode="numeric" autoComplete="off" />
                            {data.referralHint && (
                                <div className="sm:col-span-2">
                                    <TextField label="Referral code (optional)" hint={data.referralHint} error={errors.referralCode} value={referral} onValueChange={setReferral} maxLength={40} autoComplete="off" />
                                </div>
                            )}
                            <TextField label="Amount paid" hint="In pesos. Leave blank if nothing is paid yet." error={errors.amountPaid} value={paid} onValueChange={setPaid} inputMode="numeric" autoComplete="off" />
                            <SelectField label="Payment method" hint="Needed when an amount is paid." error={errors.paymentMethod} options={[{ value: 'none', label: 'Not paid' }, ...data.methods]} value={method} onChange={setMethod} />
                            <TextField label="Reference note" hint="Optional. How to find the payment again." error={errors.paymentReference} value={reference} onValueChange={setReference} placeholder="GCash ref no., receipt number…" maxLength={255} autoComplete="off" />
                        </div>
                    </Section>

                    <Section title="Notes" className="min-w-0">
                        <TextAreaField label="Private note" hint="Only the team sees this." error={errors.adminNote} value={note} onValueChange={setNote} rows={5} maxLength={2000} />
                    </Section>
                </div>

                <Button type="submit" icon="check" variant="primary" busy={busy}>
                    Save transaction
                </Button>
            </form>
        </>
    );
}
