import { type FormEvent, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { TextField } from '../../ui/Field';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { ComboboxField, type Option, SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { TextAreaField } from '../../ui/TextArea';
import { toast } from '../../ui/toast';
import { Alert, Section, useSubmit } from '../bookings/parts';

type Options = { branches: Option[]; salesReps: Option[]; can: { create: boolean } };

type Values = { name: string; phone: string | null; email: string | null; branchId: number | null; salesRepId: number | null; notes: string | null };

type Editing = { id: number; name: string; values: Values; branch: Option | null };

const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

function CustomerForm({ options, start, save, label, back, done }: { options: Options; start: Values; save: (values: Values) => Promise<void>; label: string; back: string; done: string }) {
    const [name, setName] = useState(start.name);
    const [phone, setPhone] = useState(text(start.phone));
    const [email, setEmail] = useState(text(start.email));
    const [branch, setBranch] = useState(text(start.branchId) || 'none');
    const [rep, setRep] = useState(text(start.salesRepId));
    const [notes, setNotes] = useState(text(start.notes));
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            await save({ name, phone, email, branchId: branch === 'none' ? null : Number(branch), salesRepId: rep ? Number(rep) : null, notes });
            toast.success(done);
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            {Object.keys(errors).length > 0 && !failed && <Alert title="Not saved">Check the fields marked below.</Alert>}

            <div className="grid items-start gap-4 lg:grid-cols-3">
                <Section title="Details" className="min-w-0 lg:col-span-2">
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <TextField label="Name" hint="Their full name." error={errors.name} value={name} onValueChange={setName} required maxLength={120} autoComplete="off" />
                        <TextField label="Mobile" hint="Like 0917 123 4567." type="tel" error={errors.phone} value={phone} onValueChange={setPhone} maxLength={40} autoComplete="off" />
                        <TextField label="Email" hint="Optional. Booking emails go here." type="email" error={errors.email} value={email} onValueChange={setEmail} maxLength={190} autoComplete="off" />
                        <SelectField label="Home branch" hint="Where they usually go." error={errors.branchId} options={[{ value: 'none', label: 'No home branch' }, ...options.branches]} value={branch} onChange={setBranch} />
                        <ComboboxField label="Sales rep" hint="Optional. Type to find a name; empty the box for no one." error={errors.salesRepId} options={options.salesReps} value={rep || null} onChange={setRep} />
                    </div>
                </Section>
                <Section title="Notes" className="min-w-0">
                    <TextAreaField label="Private note" hint="Only the team sees this." error={errors.notes} value={notes} onValueChange={setNotes} rows={6} maxLength={3000} />
                </Section>
            </div>

            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    {label}
                </Button>
                <Link to={back} className={buttonClass()}>
                    <Icon name="x" />
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function CustomerCreate() {
    const navigate = useNavigate();
    const { data, error, reload } = useResource<Options>('/customers/form');

    return (
        <>
            <PageHeader title="Add a customer" description="A walk-in or phone customer you add by hand." />
            {error && <ErrorState message={error} onRetry={reload} />}
            {!data ? (
                !error && <Loading rows={5} />
            ) : !data.can.create ? (
                <Alert>Your role doesn’t include adding customers.</Alert>
            ) : (
                <CustomerForm
                    options={data}
                    // One branch: it is chosen already
                    start={{ name: '', phone: '', email: '', branchId: data.branches.length === 1 ? Number(data.branches[0].value) : null, salesRepId: null, notes: '' }}
                    label="Create"
                    done="Created"
                    back="/customers"
                    save={async (values) => {
                        const made = await api<{ id: number }>('POST', '/customers', values);
                        navigate(`/customers/${made.id}`);
                    }}
                />
            )}
        </>
    );
}

export function CustomerEdit() {
    const { id } = useParams();
    const navigate = useNavigate();
    const customer = useResource<Editing>(`/customers/${id}/edit`);
    const options = useResource<Options>('/customers/form');
    const error = customer.error ?? options.error;

    if (!customer.data || !options.data || String(customer.data.id) !== id) {
        return (
            <>
                <PageHeader title="Edit customer" />
                {error ? (
                    <ErrorState
                        message={error}
                        onRetry={() => {
                            customer.reload();
                            options.reload();
                        }}
                    />
                ) : (
                    <Loading rows={5} />
                )}
            </>
        );
    }

    const { data } = customer;
    const own = data.branch && !options.data.branches.some((branch) => branch.value === data.branch!.value) ? [data.branch] : [];

    return (
        <>
            <PageHeader
                title={`Edit ${data.name}`}
                actions={
                    <Link to={`/customers/${data.id}`} className={buttonClass()}>
                        <Icon name="search" />
                        View
                    </Link>
                }
            />
            <CustomerForm
                key={data.id}
                options={{ ...options.data, branches: [...options.data.branches, ...own] }}
                start={data.values}
                label="Save changes"
                done="Saved"
                back={`/customers/${data.id}`}
                save={async (values) => {
                    await api('PATCH', `/customers/${data.id}`, values);
                    navigate(`/customers/${data.id}`);
                }}
            />
        </>
    );
}
