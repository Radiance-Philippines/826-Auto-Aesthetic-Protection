import { type FormEvent, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { confirm } from '../../ui/Dialog';
import { SwitchField, TextField } from '../../ui/Field';
import { FileUpload, shrinkImage, toFormData } from '../../ui/FileUpload';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { Repeater } from '../../ui/Repeater';
import { type Option, SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { TextAreaField } from '../../ui/TextArea';
import { toast } from '../../ui/toast';
import { Alert, CheckboxListField, Section, useSubmit } from '../bookings/parts';
import { OfferList } from '../branches/OfferList';
import { slugify } from '../branches/slugify';

type Values = {
    name: string;
    serviceCategoryId: number | null;
    slug: string;
    summary: string | null;
    estimateDays: number | string | null;
    warrantyMonths: number | string | null;
    backjobTypes: number[];
    description: string | null;
    inclusions: string[];
    isActive: boolean;
    homeServiceOk: boolean;
    sortOrder: number | string;
    rates: Record<string, number | string | null>;
    branches: number[];
};

type Options = { categories: Option[]; backjobTypes: Option[]; sizes: Option[]; branches: Option[] };

type Saved = { id: number; name: string; values: Values; options: Options; imageUrl: string | null; canDelete: boolean };

const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

type FormProps = { record: Saved | null; start: Values; options: Options; onSaved: (record: Saved, another: boolean) => void };

function Fields({ record, start, options, onSaved }: FormProps) {
    const [values, setValues] = useState(start);
    const [picture, setPicture] = useState<File[]>([]);
    const [removed, setRemoved] = useState(false);
    const { errors, failed, busy, submit } = useSubmit();

    const set = <Key extends keyof Values>(key: Key, value: Values[Key]) => setValues((current) => ({ ...current, [key]: value }));
    const field = (key: 'name' | 'slug' | 'summary' | 'estimateDays' | 'warrantyMonths' | 'sortOrder') => ({ value: text(values[key]), onValueChange: (value: string) => set(key, value as never), error: errors[key] });

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';

        void submit(async () => {
            const body = { ...values, removeImage: removed, image: picture[0] ? await shrinkImage(picture[0]) : undefined };
            const saved = await api<Saved>('POST', record ? `/services/${record.id}` : '/services', body.image ? toFormData(body) : body);
            toast.success(record ? 'Saved' : 'Created');
            onSaved(saved, another);
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            {Object.keys(errors).length > 0 && !failed && <Alert title="Not saved">Check the fields marked below.</Alert>}

            <div className="grid items-start gap-4 lg:grid-cols-3">
                <Section title="Service" className="min-w-0 lg:col-span-2">
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <TextField label="Name" hint="What customers pick." {...field('name')} onBlur={() => !record && set('slug', slugify(values.name))} placeholder="Graphene coating" required maxLength={120} autoComplete="off" />
                        <SelectField label="Category" hint="Its group on the services page." error={errors.serviceCategoryId} options={options.categories} value={values.serviceCategoryId === null ? null : String(values.serviceCategoryId)} onChange={(value) => set('serviceCategoryId', Number(value))} />
                        <TextField label="Web address" hint={`Its page: /services/${values.slug || '…'}. Letters, numbers, dashes and underscores.`} {...field('slug')} required maxLength={80} autoComplete="off" autoCapitalize="none" spellCheck={false} />
                        <TextField label="One-line summary" hint="Shown in lists of services." {...field('summary')} maxLength={160} autoComplete="off" />
                        <TextField label="Takes (days)" hint="Open days, counting the drop-off day. Leave blank until you know it: a service without it can’t be booked." inputMode="numeric" {...field('estimateDays')} autoComplete="off" />
                        <TextField label="Warranty (months)" hint="Counted from the day the unit exits. Leave blank if the service has no warranty. Bookings already made keep what they were booked with." inputMode="numeric" {...field('warrantyMonths')} autoComplete="off" />
                        <div className="sm:col-span-2">
                            {options.backjobTypes.length ? (
                                <CheckboxListField label="Covers backjobs for" hint="Define the kinds of backjob in Shop → Backjob types." error={errors.backjobTypes} columns options={options.backjobTypes} value={values.backjobTypes.map(String)} onChange={(value) => set('backjobTypes', value.map(Number))} />
                            ) : (
                                <p className="m-0 text-sm">
                                    <span className="block font-medium">Covers backjobs for</span>
                                    <span className="text-muted-foreground">No backjob types yet. Define the kinds of backjob in Shop → Backjob types.</span>
                                </p>
                            )}
                        </div>
                        <TextAreaField label="Description" hint="The service’s page text." error={errors.description} value={text(values.description)} onValueChange={(value) => set('description', value)} rows={4} maxLength={3000} className="sm:col-span-2" />
                        <div className="min-w-0 sm:col-span-2">
                            <Repeater
                                label="What’s included"
                                hint="One thing per line. Drag the handle to put them in order."
                                error={errors.inclusions}
                                lineErrors={values.inclusions.map((_, index) => errors[`inclusions.${index}`])}
                                value={values.inclusions}
                                onChange={(value) => set('inclusions', value)}
                                addLabel="Add a line"
                                maxLength={160}
                            />
                        </div>
                    </div>
                </Section>

                <Section title="On the site" className="min-w-0">
                    <div className="space-y-4">
                        <SwitchField label="Bookable" hint="Off hides it from the site and the booking form." checked={values.isActive} onChange={(on) => set('isActive', on)} />
                        <SwitchField label="Can be done at home" hint="Offered in home-service requests." checked={values.homeServiceOk} onChange={(on) => set('homeServiceOk', on)} />
                        <FileUpload
                            label="Picture"
                            hint="JPG, PNG or WebP, up to 20 MB."
                            error={errors.image}
                            value={picture}
                            onChange={setPicture}
                            maxMb={20}
                            current={removed ? null : record?.imageUrl}
                            onRemoveCurrent={() => setRemoved(true)}
                        />
                        <TextField label="Order" hint="Lower numbers come first." inputMode="numeric" {...field('sortOrder')} autoComplete="off" />
                    </div>
                </Section>
            </div>

            <Section title="Prices by size class">
                <p className="mt-0 mb-3 text-sm text-muted-foreground">
                    One price per size class, never shown to customers: it prices a booking once the team has seen the unit and picked its class. Leave a class blank if the service isn’t offered for it.
                </p>
                {options.sizes.length === 0 && <p className="m-0 text-sm">No size classes yet. Add them in Shop → Vehicle sizes.</p>}
                <div className="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {options.sizes.map((size) => (
                        <TextField
                            key={size.value}
                            label={size.label}
                            hint="Pesos (₱), whole numbers."
                            inputMode="numeric"
                            error={errors[`rates.${size.value}`]}
                            value={text(values.rates[size.value])}
                            onValueChange={(price) => set('rates', { ...values.rates, [size.value]: price })}
                            autoComplete="off"
                        />
                    ))}
                </div>
            </Section>

            <Section title="Branches">
                {options.branches.length ? (
                    <OfferList label="Offered at" hint="Customers can book it at these branches." error={errors.branches} options={options.branches} value={values.branches} onChange={(value) => set('branches', value)} />
                ) : (
                    <p className="m-0 text-sm text-muted-foreground">No branches yet. Add them in Shop → Branches.</p>
                )}
            </Section>

            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon={record ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {record ? 'Save changes' : 'Create'}
                </Button>
                {!record && (
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                )}
                <Link to="/services" className={buttonClass()}>
                    <Icon name="x" />
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function ServiceForm() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { data, error, reload } = useResource<Saved>(id ? `/services/${id}` : '/services/form');
    /** The record as the last save answered it */
    const [saved, setSaved] = useState<Saved | null>(null);
    const [round, setRound] = useState(0);
    const loaded = saved && String(saved.id) === id ? saved : data;
    const record = id && loaded && String(loaded.id) === id ? loaded : null;
    const ready = id ? record : data && !data.id ? data : null;

    const afterSave = (next: Saved, another: boolean) => {
        setRound((current) => current + 1);

        if (id) {
            setSaved(next);
        } else if (!another) {
            setSaved(next);
            navigate(`/services/${next.id}/edit`);
        }

        window.scrollTo(0, 0);
    };

    const remove = async () => {
        const yes = await confirm({ title: `Delete ${record!.name}`, description: 'Bookings already made keep this service’s name, estimate and price. Turning off “Bookable” hides it instead.', label: 'Delete', icon: 'trash-2', destructive: true });

        if (!yes) {
            return;
        }

        try {
            await api('DELETE', `/services/${record!.id}`);
            toast.success('Deleted');
            navigate('/services');
        } catch (failure) {
            toast.error(errorMessage(failure));
            reload();
        }
    };

    return (
        <>
            <PageHeader
                title={id ? `Edit ${record?.name ?? 'service'}` : 'Create service'}
                actions={
                    <>
                        <Link to="/services" className={buttonClass()}>
                            <Icon name="arrow-left" />
                            Services
                        </Link>
                        {record?.canDelete && (
                            <Button icon="trash-2" variant="destructive" onClick={remove}>
                                Delete
                            </Button>
                        )}
                    </>
                }
            />

            {error && <ErrorState message={error} onRetry={reload} />}

            {ready ? <Fields key={`${id ?? 'new'}-${round}`} record={record} start={ready.values} options={ready.options} onSaved={afterSave} /> : !error && <Loading rows={5} />}
        </>
    );
}
