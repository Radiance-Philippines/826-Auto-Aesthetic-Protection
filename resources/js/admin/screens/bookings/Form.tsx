import { type FormEvent, type ReactNode, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useMe } from '../../lib/me';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { DateField } from '../../ui/DateField';
import { confirm } from '../../ui/Dialog';
import { SwitchField, TextField } from '../../ui/Field';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { TextAreaField } from '../../ui/TextArea';
import { toast } from '../../ui/toast';
import { Alert, CheckboxListField, ChoiceField, Section, useSubmit } from './parts';

type Options = {
    branches: Option[];
    services: Option[];
    blocked: string[];
    canOverbook: boolean;
    sizes: Option[];
    kinds: Option[];
    sources: Option[];
    windows: Option[];
    types: Option[];
    maxYear: number;
    can: { create: boolean };
};

type Values = {
    vehicleYear: string;
    vehicleModel: string;
    vehicleType: string;
    plateNumber: string;
    address: string;
    area: string;
    landmark: string;
    hasWater: string;
    hasPower: string;
    hasShade: string;
    name: string;
    phone: string;
    email: string;
    message: string;
    adminNote: string;
};

const empty: Values = { vehicleYear: '', vehicleModel: '', vehicleType: '', plateNumber: '', address: '', area: '', landmark: '', hasWater: '', hasPower: '', hasShade: '', name: '', phone: '', email: '', message: '', adminNote: '' };

type FieldsProps = { values: Values; set: (key: keyof Values) => (value: string) => void; errors: Record<string, string> };

/** "yes" | "no" | "" → true | false | null */
const yesNo = (value: string) => (value === '' ? null : value === 'yes');

const sent = (values: Values) => ({ ...values, vehicleType: values.vehicleType || null, hasWater: yesNo(values.hasWater), hasPower: yesNo(values.hasPower), hasShade: yesNo(values.hasShade) });

function VehicleFields({ values, set, errors, types, maxYear, children }: FieldsProps & { types: Option[]; maxYear: number; children?: ReactNode }) {
    return (
        <Section title="Vehicle">
            <div className="grid items-start gap-4 sm:grid-cols-2">
                <TextField label="Year" hint={`Between 1950 and ${maxYear}.`} error={errors.vehicleYear} value={values.vehicleYear} onValueChange={set('vehicleYear')} inputMode="numeric" maxLength={4} autoComplete="off" />
                <TextField label="Make and model" hint="Like Toyota Vios." error={errors.vehicleModel} value={values.vehicleModel} onValueChange={set('vehicleModel')} placeholder="Toyota Vios" maxLength={80} autoComplete="off" />
                <SelectField label="Type" hint="The body style." error={errors.vehicleType} options={types} value={values.vehicleType || null} onChange={set('vehicleType')} />
                <TextField label="Plate number" hint="Letters and numbers; spaces are dropped." error={errors.plateNumber} value={values.plateNumber} onValueChange={set('plateNumber')} maxLength={15} autoComplete="off" />
                {children}
            </div>
        </Section>
    );
}

function HomeFields({ values, set, errors, disabled }: FieldsProps & { disabled: boolean }) {
    const answers = [
        { value: 'yes', label: 'Yes', icon: 'check' as const },
        { value: 'no', label: 'No', icon: 'x' as const },
    ];

    return (
        <Section title="Home service">
            <fieldset disabled={disabled} className="m-0 grid min-w-0 items-start gap-4 border-0 p-0 sm:grid-cols-2">
                <TextAreaField label="Address" hint="Where the team goes." error={errors.address} value={values.address} onValueChange={set('address')} maxLength={500} rows={2} required className="sm:col-span-2" />
                <TextField label="Area" hint="Barangay or town." error={errors.area} value={values.area} onValueChange={set('area')} maxLength={120} autoComplete="off" />
                <TextField label="Landmark" hint="What helps the team find it." error={errors.landmark} value={values.landmark} onValueChange={set('landmark')} maxLength={200} autoComplete="off" />
                <ChoiceField label="Water hose" hint="Can the team use one there?" error={errors.hasWater} options={answers} value={values.hasWater} onChange={set('hasWater')} />
                <ChoiceField label="Power outlet" hint="Can the team plug in?" error={errors.hasPower} options={answers} value={values.hasPower} onChange={set('hasPower')} />
                <ChoiceField label="Shade or garage" hint="Is there a covered spot?" error={errors.hasShade} options={answers} value={values.hasShade} onChange={set('hasShade')} />
            </fieldset>
        </Section>
    );
}

function CustomerFields({ values, set, errors, disabled }: FieldsProps & { disabled: boolean }) {
    return (
        <>
            <Section title="Customer">
                <fieldset disabled={disabled} className="m-0 min-w-0 space-y-4 border-0 p-0">
                    <TextField label="Name" hint="Who to look for." error={errors.name} value={values.name} onValueChange={set('name')} required maxLength={120} autoComplete="off" />
                    <TextField label="Mobile" hint="Like 0917 123 4567. The same number is the same customer." type="tel" error={errors.phone} value={values.phone} onValueChange={set('phone')} required maxLength={40} autoComplete="off" />
                    <TextField label="Email" hint="Optional. Booking emails go here." type="email" error={errors.email} value={values.email} onValueChange={set('email')} maxLength={190} autoComplete="off" />
                </fieldset>
            </Section>
            <Section title="Notes">
                <fieldset disabled={disabled} className="m-0 min-w-0 space-y-4 border-0 p-0">
                    <TextAreaField label="From the customer" hint="What they asked for." error={errors.message} value={values.message} onValueChange={set('message')} maxLength={1000} />
                    <TextAreaField label="Private note" hint="Only the team sees this." error={errors.adminNote} value={values.adminNote} onValueChange={set('adminNote')} maxLength={2000} />
                </fieldset>
            </Section>
        </>
    );
}

function useValues(start: Values) {
    const [values, setValues] = useState(start);
    const set = (key: keyof Values) => (value: string) => setValues((current) => ({ ...current, [key]: value }));

    return { values, set };
}

const columns = 'grid items-start gap-4 lg:grid-cols-3';

export function BookingCreate() {
    // "Create & create another" starts over with an empty form
    const [round, setRound] = useState(0);

    return <CreateForm key={round} onAnother={() => setRound((current) => current + 1)} />;
}

function CreateForm({ onAnother }: { onAnother: () => void }) {
    const navigate = useNavigate();
    const { reload: reloadMe } = useMe();
    const [kind, setKind] = useState('onsite');
    const [source, setSource] = useState('phone');
    const [branch, setBranch] = useState<string | null>(null);
    const [services, setServices] = useState<string[]>([]);
    const [dropOff, setDropOff] = useState('');
    const [force, setForce] = useState(false);
    const [preferred, setPreferred] = useState('');
    const [window_, setWindow] = useState('any');
    const [size, setSize] = useState('none');
    const { values, set } = useValues(empty);
    const { errors, failed, busy, submit } = useSubmit();

    const query = new URLSearchParams({ kind, ...(branch ? { branch } : {}), ...(dropOff ? { date: dropOff } : {}) });
    const { data, error, reload } = useResource<Options>(`/bookings/form?${query}`);
    const home = kind === 'home';

    // One branch: it is chosen already
    if (data && !branch && data.branches.length === 1) {
        setBranch(data.branches[0].value);
    }

    if (!data) {
        return (
            <>
                <PageHeader title="Add a booking" />
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={6} />}
            </>
        );
    }

    if (!data.can.create) {
        return (
            <>
                <PageHeader title="Add a booking" />
                <Alert>Your role doesn’t include adding bookings.</Alert>
            </>
        );
    }

    const offered = services.filter((id) => data.services.some((service) => service.value === id));

    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';
        void submit(async () => {
            const booked = await api<{ id: number; message: string }>('POST', '/bookings', {
                ...sent(values),
                kind,
                source,
                branchId: branch ? Number(branch) : null,
                services: offered.map(Number),
                dropOffOn: dropOff || null,
                force: !home && data.canOverbook && force,
                preferredDate: home ? preferred || null : null,
                preferredWindow: home ? window_ : null,
                vehicleSizeId: size === 'none' ? null : Number(size),
            });
            toast.success(booked.message);
            reloadMe();

            if (another) {
                window.scrollTo(0, 0);
                onAnother();
            } else {
                navigate(`/bookings/${booked.id}`);
            }
        });
    };

    const problem = errors.dropOffOn;

    return (
        <>
            <PageHeader title="Add a booking" />
            <form onSubmit={save} noValidate className="space-y-4">
                {failed && <Alert title="Not booked">{failed}</Alert>}
                {problem && <Alert title="Not booked">{problem}</Alert>}
                {Object.keys(errors).length > 0 && !problem && !failed && <Alert title="Not booked">Check the fields marked below.</Alert>}

                <div className={columns}>
                    <div className="min-w-0 space-y-4 lg:col-span-2">
                        <Section title="Booking">
                            <div className="grid items-start gap-4 sm:grid-cols-2">
                                <ChoiceField
                                    label="Kind"
                                    hint="At the branch, or at the customer’s."
                                    error={errors.kind}
                                    options={data.kinds.map((option) => ({ ...option, icon: option.value === 'home' ? ('house' as const) : ('store' as const) }))}
                                    value={kind}
                                    onChange={setKind}
                                />
                                <SelectField label="Came in by" hint="How they reached you." error={errors.source} options={data.sources} value={source} onChange={setSource} />
                                <SelectField
                                    label="Branch"
                                    hint="Where the work is booked."
                                    error={errors.branchId}
                                    options={data.branches}
                                    value={branch}
                                    onChange={(next) => {
                                        setBranch(next);
                                        setServices([]);
                                    }}
                                />
                                <div className="sm:col-span-2">
                                    <CheckboxListField label="Services" hint={branch ? 'Only services this branch offers that have a day estimate.' : 'Pick the branch first.'} error={errors.services ?? errors['services.0']} options={data.services} value={offered} onChange={setServices} />
                                </div>
                                <DateField
                                    label={home ? 'Team comes on (optional)' : 'Drop-off date'}
                                    hint={home ? 'Leave blank to schedule it later.' : 'The ready date is worked out from the services and the days the branch is open.'}
                                    error={problem}
                                    value={dropOff || null}
                                    onChange={setDropOff}
                                    unavailable={data.blocked}
                                    clearable
                                />
                                {!home && data.canOverbook && <SwitchField label="Book even if it’s full" hint="For when you’ve made room yourself. The queue shows the day as over its cap." checked={force} onChange={setForce} />}
                                {home && (
                                    <>
                                        <DateField label="Customer’s preferred day" hint="Optional." error={errors.preferredDate} value={preferred || null} onChange={setPreferred} clearable />
                                        <SelectField label="Preferred time" hint="When they would rather have the team." error={errors.preferredWindow} options={data.windows} value={window_} onChange={setWindow} />
                                    </>
                                )}
                            </div>
                        </Section>

                        <VehicleFields values={values} set={set} errors={errors} types={data.types} maxYear={data.maxYear}>
                            <SelectField label="Size class (if you know it)" hint="Leave blank to review the unit later: the size sets the price." error={errors.vehicleSizeId} options={[{ value: 'none', label: 'Review later' }, ...data.sizes]} value={size} onChange={setSize} />
                        </VehicleFields>

                        {home && <HomeFields values={values} set={set} errors={errors} disabled={false} />}
                    </div>

                    <div className="min-w-0 space-y-4">
                        <CustomerFields values={values} set={set} errors={errors} disabled={false} />
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" icon="plus" variant="primary" busy={busy}>
                        Create
                    </Button>
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                    <Link to="/bookings" className={buttonClass()}>
                        <Icon name="x" />
                        Cancel
                    </Link>
                </div>
            </form>
        </>
    );
}

type Editing = {
    id: number;
    title: string;
    isHome: boolean;
    sales: boolean;
    canDelete: boolean;
    values: { [Key in keyof Values]: string | number | boolean | null };
};

function EditForm({ booking, options }: { booking: Editing; options: Options }) {
    const navigate = useNavigate();
    const { reload: reloadMe } = useMe();
    const answer = (value: unknown) => (value === null || value === undefined ? '' : value ? 'yes' : 'no');
    const { values, set } = useValues({
        ...(Object.fromEntries(Object.entries(booking.values).map(([key, value]) => [key, value === null ? '' : String(value)])) as Values),
        hasWater: answer(booking.values.hasWater),
        hasPower: answer(booking.values.hasPower),
        hasShade: answer(booking.values.hasShade),
    });
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            await api('PATCH', `/bookings/${booking.id}`, sent(values));
            toast.success('Saved');
            navigate(`/bookings/${booking.id}`);
        });
    };

    const remove = async () => {
        if (!(await confirm({ title: 'Delete booking', description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/bookings/${booking.id}`);
            toast.success('Deleted');
            reloadMe();
            navigate('/bookings');
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    const disabled = !booking.sales;

    return (
        <>
            <PageHeader
                title={`Edit ${booking.title}`}
                actions={
                    <>
                        <Link to={`/bookings/${booking.id}`} className={buttonClass()}>
                            <Icon name="search" />
                            View
                        </Link>
                        {booking.canDelete && (
                            <Button icon="trash-2" variant="destructive" onClick={remove}>
                                Delete
                            </Button>
                        )}
                    </>
                }
            />
            <form onSubmit={save} noValidate className="space-y-4">
                {failed && <Alert title="Not saved">{failed}</Alert>}
                {Object.keys(errors).length > 0 && !failed && <Alert title="Not saved">Check the fields marked below.</Alert>}
                {disabled && <p className="text-sm text-muted-foreground">Your role can correct the vehicle. The rest is for the sales side.</p>}

                <div className={columns}>
                    <div className="min-w-0 space-y-4 lg:col-span-2">
                        <VehicleFields values={values} set={set} errors={errors} types={options.types} maxYear={options.maxYear} />
                        {booking.isHome && <HomeFields values={values} set={set} errors={errors} disabled={disabled} />}
                    </div>
                    <div className="min-w-0 space-y-4">
                        <CustomerFields values={values} set={set} errors={errors} disabled={disabled} />
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" icon="save" variant="primary" busy={busy}>
                        Save changes
                    </Button>
                    <Link to={`/bookings/${booking.id}`} className={buttonClass()}>
                        <Icon name="x" />
                        Cancel
                    </Link>
                </div>
            </form>
        </>
    );
}

export function BookingEdit() {
    const { id } = useParams();
    const booking = useResource<Editing>(`/bookings/${id}/edit`);
    const options = useResource<Options>('/bookings/form');
    const error = booking.error ?? options.error;

    if (!booking.data || !options.data || String(booking.data.id) !== id) {
        return (
            <>
                <PageHeader title="Edit booking" />
                {error ? (
                    <ErrorState
                        message={error}
                        onRetry={() => {
                            booking.reload();
                            options.reload();
                        }}
                    />
                ) : (
                    <Loading rows={6} />
                )}
            </>
        );
    }

    return <EditForm key={booking.data.id} booking={booking.data} options={options.data} />;
}
