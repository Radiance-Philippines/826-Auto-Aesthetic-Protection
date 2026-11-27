import { type FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { confirm } from '../../ui/Dialog';
import { SwitchField, TextField } from '../../ui/Field';
import { FileUpload, toFormData } from '../../ui/FileUpload';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { TabBar } from '../../ui/Tabs';
import { TextAreaField } from '../../ui/TextArea';
import { TimeField } from '../../ui/TimeField';
import { toast } from '../../ui/toast';
import { Alert, CheckboxListField, Section, useSubmit } from '../bookings/parts';
import { OfferList } from './OfferList';
import { slugify } from './slugify';
import { TagsField } from './TagsField';

type Method = 'gcash' | 'maya';

type Day = { openToday: boolean; open: string | null; close: string | null };

type Values = {
    name: string;
    slug: string;
    status: string;
    city: string | null;
    address: string | null;
    phone: string | null;
    messengerUrl: string | null;
    facebookUrl: string | null;
    mapUrl: string | null;
    mapLat: number | string | null;
    mapLng: number | string | null;
    alertEmail: string | null;
    sortOrder: number | string;
    dailyCap: number | string;
    leadDays: number | string;
    monthsAhead: number;
    weeklyHours: Record<string, Day>;
    services: number[];
    offersHomeService: boolean;
    homeServiceAreas: string[];
    facebookPageId: string | null;
    staff: number[];
    gcashEnabled: boolean;
    gcashName: string | null;
    gcashNumber: string | null;
    mayaEnabled: boolean;
    mayaName: string | null;
    mayaNumber: string | null;
};

type Facebook = { pageName: string | null; hasToken: boolean; status: string };

type Options = { statuses: Option[]; services: Option[]; staff: Option[] };

type Saved = {
    id: number;
    name: string;
    values: Values;
    options: Options;
    gcashQrUrl: string | null;
    mayaQrUrl: string | null;
    missing: string[];
    warnings: { title: string; body: string }[];
    canDelete: boolean;
    siteUrl: string | null;
    facebook: Facebook;
};

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const MONTHS = Array.from({ length: 12 }, (_, index) => ({ value: String(index + 1), label: index === 0 ? '1 month ahead' : `${index + 1} months ahead` }));

const TABS = [
    { value: 'info', label: 'Info', icon: 'map-pin' as const, fields: ['name', 'slug', 'status', 'city', 'address', 'phone', 'messengerUrl', 'facebookUrl', 'mapUrl', 'mapLat', 'mapLng', 'alertEmail', 'sortOrder'] },
    { value: 'days', label: 'Days and cap', icon: 'clock' as const, fields: ['dailyCap', 'leadDays', 'monthsAhead', 'weeklyHours'] },
    { value: 'services', label: 'Services', icon: 'sparkles' as const, fields: ['services'] },
    { value: 'home', label: 'Home service', icon: 'house' as const, fields: ['offersHomeService', 'homeServiceAreas'] },
    { value: 'payments', label: 'Payments', icon: 'qr-code' as const, fields: ['gcashEnabled', 'gcashQr', 'gcashName', 'gcashNumber', 'mayaEnabled', 'mayaQr', 'mayaName', 'mayaNumber'] },
    { value: 'facebook', label: 'Facebook page', icon: 'message-circle' as const, fields: ['facebookPageId', 'facebookPageToken'] },
    { value: 'staff', label: 'Staff', icon: 'users' as const, fields: ['staff'] },
];

const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

type FormProps = { record: Saved | null; start: Values; options: Options; onSaved: (record: Saved, another: boolean) => void };

function Fields({ record, start, options, onSaved }: FormProps) {
    const [values, setValues] = useState(start);
    const [qr, setQr] = useState<Record<Method, File[]>>({ gcash: [], maya: [] });
    const [removed, setRemoved] = useState<Record<Method, boolean>>({ gcash: false, maya: false });
    const [token, setToken] = useState('');
    const [facebook, setFacebook] = useState(record?.facebook ?? { pageName: null, hasToken: false, status: 'Not connected' });
    const [check, setCheck] = useState<{ title: string; body: string; tone: 'error' | 'warning' } | null>(null);
    const { errors, failed, busy, submit } = useSubmit();
    const [params, setParams] = useSearchParams();
    const asked = params.get('tab');
    const tab = TABS.some((each) => each.value === asked) ? asked! : 'info';
    const showTab = (value: string) => setParams({ tab: value }, { replace: true });

    const set = <Key extends keyof Values>(key: Key, value: Values[Key]) => setValues((current) => ({ ...current, [key]: value }));
    const setDay = (day: number, changes: Partial<Day>) => setValues((current) => ({ ...current, weeklyHours: { ...current.weeklyHours, [day]: { ...current.weeklyHours[day], ...changes } } }));
    const field = (key: keyof Values) => ({ value: text(values[key] as string | number | null), onValueChange: (value: string) => set(key, value as never), error: errors[key] });

    const wrong = (fields: string[]) => Object.keys(errors).filter((key) => fields.includes(key.split('.')[0])).length;

    // A refused save opens the first tab with something to correct
    useEffect(() => {
        const first = TABS.find((each) => wrong(each.fields) > 0);

        if (first && !wrong(TABS.find((each) => each.value === tab)!.fields)) {
            showTab(first.value);
        }
        // Only when the server answers
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors]);

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const another = (event.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'another';
        const body = {
            ...values,
            removegcashQr: removed.gcash,
            removemayaQr: removed.maya,
            facebookPageToken: token || undefined,
            gcashQr: qr.gcash[0],
            mayaQr: qr.maya[0],
        };

        void submit(async () => {
            const saved = await api<Saved>('POST', record ? `/branches/${record.id}` : '/branches', qr.gcash.length || qr.maya.length ? toFormData(body) : body);
            toast.success(record ? 'Saved' : 'Created');
            onSaved(saved, another);
        });
    };

    const connect = async () => {
        setCheck(null);

        try {
            const answer = await api<{ title: string; body: string; warning: boolean; facebook: Facebook }>('POST', `/branches/${record!.id}/connect`, { facebookPageId: values.facebookPageId, facebookPageToken: token });
            setFacebook(answer.facebook);
            setToken('');

            if (answer.warning) {
                setCheck({ title: answer.title, body: answer.body, tone: 'warning' });
            } else {
                toast.success(answer.title, { description: answer.body });
            }
        } catch (failure) {
            setCheck({ title: 'Not connected', body: errorMessage(failure), tone: 'error' });
        }
    };

    const payment = (method: Method, label: string) => (
        <Section title={label} className="min-w-0">
            <div className="space-y-4">
                <SwitchField label={`Use this branch’s ${label}`} hint="On: this branch’s fees go to this account." checked={values[`${method}Enabled`]} onChange={(on) => set(`${method}Enabled`, on)} />
                <FileUpload
                    label={`${label} QR code`}
                    hint="PNG, JPG or WebP, up to 5 MB. Kept exactly as uploaded so it scans."
                    error={errors[`${method}Qr`]}
                    value={qr[method]}
                    onChange={(files) => setQr((current) => ({ ...current, [method]: files }))}
                    maxMb={5}
                    current={removed[method] ? null : record?.[`${method}QrUrl`]}
                    onRemoveCurrent={() => setRemoved((current) => ({ ...current, [method]: true }))}
                />
                <TextField label={`${label} account name`} hint="As the customer sees it before paying." {...field(`${method}Name`)} maxLength={120} autoComplete="off" />
                <TextField label={`${label} number`} hint="Like 0917 123 4567." type="tel" {...field(`${method}Number`)} maxLength={40} autoComplete="off" />
            </div>
        </Section>
    );

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            {Object.keys(errors).length > 0 && !failed && <Alert title="Not saved">Check the fields marked below.</Alert>}

            <TabBar label="Branch" tabs={TABS.map((each) => ({ ...each, badge: wrong(each.fields) }))} value={tab} onChange={showTab}>
                {tab === 'info' && (
                    <Section title="Info">
                        <div className="grid items-start gap-4 sm:grid-cols-2">
                            <TextField
                                label="Name"
                                hint="The branch as customers see it."
                                {...field('name')}
                                onBlur={() => !record && set('slug', slugify(values.name))}
                                placeholder="Cainta – Ortigas Extension"
                                required
                                maxLength={120}
                                autoComplete="off"
                            />
                            <TextField label="Web address" hint={`Its page: /branches/${values.slug || '…'}. Letters, numbers, dashes and underscores.`} {...field('slug')} required maxLength={80} autoComplete="off" autoCapitalize="none" spellCheck={false} />
                            <SelectField label="Status" hint="Draft: hidden. Coming soon: listed with no booking. Open: customers reserve online once nothing is missing." error={errors.status} options={options.statuses} value={values.status} onChange={(value) => set('status', value)} />
                            <TextField label="City" hint="Shown under the branch name." {...field('city')} maxLength={80} autoComplete="off" />
                            <TextAreaField label="Address" hint="Street, barangay and city." error={errors.address} value={text(values.address)} onValueChange={(value) => set('address', value)} rows={2} maxLength={500} className="sm:col-span-2" />
                            <TextField label="Phone" hint="Like 0917 123 4567." type="tel" {...field('phone')} maxLength={40} autoComplete="off" />
                            <TextField label="Messenger link" hint="Where “Message us” goes." type="url" placeholder="https://m.me/…" {...field('messengerUrl')} maxLength={255} autoComplete="off" />
                            <TextField label="Facebook page link" hint="The branch’s public page." type="url" placeholder="https://www.facebook.com/…" {...field('facebookUrl')} maxLength={255} autoComplete="off" />
                            <TextField label="Google Maps link" hint="The pin customers open for directions." type="url" {...field('mapUrl')} maxLength={500} autoComplete="off" className="sm:col-span-2" />
                            <TextField label="Map pin latitude" hint="A number between 4 and 22." inputMode="decimal" {...field('mapLat')} autoComplete="off" />
                            <TextField label="Map pin longitude" hint="A number between 116 and 127." inputMode="decimal" {...field('mapLng')} autoComplete="off" />
                            <TextField label="Email new bookings to" hint="Leave blank to use the address in Email settings." type="email" {...field('alertEmail')} maxLength={190} autoComplete="off" />
                            <TextField label="Order on the site" hint="Lower numbers come first." inputMode="numeric" {...field('sortOrder')} autoComplete="off" />
                        </div>
                    </Section>
                )}

                {tab === 'days' && (
                    <>
                        <Section title="Daily cap and notice">
                            <div className="grid items-start gap-4 sm:grid-cols-2">
                                <TextField label="Units per day (daily cap)" hint="How many units customers can book to drop off on the same day. 0 turns off online bookings." inputMode="numeric" {...field('dailyCap')} required autoComplete="off" />
                                <TextField label="Notice needed (days)" hint="The earliest drop-off date, counted from today. 1 means from tomorrow." inputMode="numeric" {...field('leadDays')} required autoComplete="off" />
                                <SelectField label="Book up to" hint="How far ahead customers can reserve." error={errors.monthsAhead} options={MONTHS} value={String(values.monthsAhead)} onChange={(value) => set('monthsAhead', Number(value))} />
                            </div>
                        </Section>
                        <Section title="Weekly hours">
                            <p className="mt-0 mb-3 text-sm text-muted-foreground">
                                The days the branch is open; the times are shown on the site. A day left off is closed: customers can’t drop off then and it doesn’t count toward a job’s days.
                            </p>
                            <div className="grid items-start gap-3 md:grid-cols-2 2xl:grid-cols-3">
                                {DAYS.map((label, index) => {
                                    const day = index + 1;
                                    const hours = values.weeklyHours[day];

                                    return (
                                        <fieldset key={day} className="m-0 min-w-0 rounded-md border border-border p-3">
                                            <legend className="px-1 text-sm font-medium">{label}</legend>
                                            <SwitchField label="Open" hint="Off: closed that day." checked={hours.openToday} onChange={(openToday) => setDay(day, { openToday })} />
                                            {hours.openToday && (
                                                <div className="mt-3 grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] items-start gap-3">
                                                    <TimeField label="Opens" hint="Like 8:00 AM." error={errors[`weeklyHours.${day}.open`]} value={hours.open ?? ''} onChange={(open) => setDay(day, { open })} />
                                                    <TimeField label="Closes" hint="After it opens." error={errors[`weeklyHours.${day}.close`]} value={hours.close ?? ''} onChange={(close) => setDay(day, { close })} />
                                                </div>
                                            )}
                                        </fieldset>
                                    );
                                })}
                            </div>
                        </Section>
                    </>
                )}

                {tab === 'services' && (
                    <Section title="Services">
                        {options.services.length ? (
                            <OfferList label="Offered at this branch" hint="Day estimates and prices are set per service (Shop → Services)." error={errors.services} options={options.services} value={values.services} onChange={(value) => set('services', value)} />
                        ) : (
                            <p className="m-0 text-sm text-muted-foreground">No services yet. Add them in Shop → Services.</p>
                        )}
                    </Section>
                )}

                {tab === 'home' && (
                    <Section title="Home service">
                        <div className="space-y-4">
                            <SwitchField label="Takes home-service requests" hint="Customers send a request with their address; the branch calls to set a time. Only services marked “Can be done at home” are offered." checked={values.offersHomeService} onChange={(on) => set('offersHomeService', on)} />
                            {values.offersHomeService && (
                                <TagsField label="Areas covered" hint="Customers pick one of these (or Other). Leave empty to let them type their area." placeholder="Add a city or barangay" error={errors.homeServiceAreas} value={values.homeServiceAreas} onChange={(value) => set('homeServiceAreas', value)} />
                            )}
                        </div>
                    </Section>
                )}

                {tab === 'payments' && (
                    <>
                        <p className="m-0 rounded-xl border border-border bg-info px-4 py-3 text-sm text-info-foreground">
                            <strong className="block">Optional</strong>
                            Add this branch’s own GCash or Maya if its reservation fees go to a different account. With none switched on here, customers pay to the main accounts in Settings → Payments.
                        </p>
                        <div className="grid items-start gap-4 lg:grid-cols-2">
                            {payment('gcash', 'GCash')}
                            {payment('maya', 'Maya')}
                        </div>
                    </>
                )}

                {tab === 'facebook' && (
                    <Section title="Messenger chats for this branch">
                        <p className="mt-0 mb-4 text-sm text-muted-foreground">
                            Customers who message this Page show up in Inbox under this branch. The owner sets up the Meta app first (Settings → Meta). Paste the Page ID and a Page access token, save, then press Connect check.
                        </p>
                        {check && (
                            <div className="mb-4">
                                <Alert title={check.title} tone={check.tone}>
                                    {check.body}
                                </Alert>
                            </div>
                        )}
                        <div className="grid items-start gap-4 sm:grid-cols-2">
                            <TextField label="Facebook Page ID" hint="Page → About → Page transparency." inputMode="numeric" {...field('facebookPageId')} maxLength={50} autoComplete="off" />
                            <TextField label="Page name" hint="Filled in by Connect check." value={facebook.pageName ?? ''} onValueChange={() => {}} disabled />
                            <TextField
                                label={facebook.hasToken ? 'New Page access token' : 'Page access token'}
                                hint={facebook.hasToken ? 'A token is saved (hidden). Leave blank to keep it, or paste a new one to replace it.' : 'Generated for this Page in your Meta app (Messenger → Generate token). Stored encrypted.'}
                                type="password"
                                error={errors.facebookPageToken}
                                value={token}
                                onValueChange={setToken}
                                maxLength={1000}
                                autoComplete="new-password"
                                className="sm:col-span-2"
                            />
                        </div>
                        {record && (
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <Button type="button" icon="plug" onClick={connect}>
                                    Connect check
                                </Button>
                            </div>
                        )}
                        <p className="mt-3 mb-0 text-sm">
                            <span className="text-muted-foreground">Status: </span>
                            {facebook.status}
                        </p>
                    </Section>
                )}

                {tab === 'staff' && (
                    <Section title="Staff">
                        {options.staff.length ? (
                            <CheckboxListField
                                label="Team members at this branch"
                                hint="They see this branch’s bookings, queue and closures. Super admins see every branch. Invite people in Settings → Users."
                                error={errors.staff}
                                columns
                                options={options.staff}
                                value={values.staff.map(String)}
                                onChange={(value) => set('staff', value.map(Number))}
                            />
                        ) : (
                            <p className="m-0 text-sm text-muted-foreground">No team members yet. Invite people in Settings → Users.</p>
                        )}
                    </Section>
                )}
            </TabBar>

            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon={record ? 'save' : 'plus'} variant="primary" busy={busy}>
                    {record ? 'Save changes' : 'Create'}
                </Button>
                {!record && (
                    <Button type="submit" value="another" icon="plus" busy={busy}>
                        Create & create another
                    </Button>
                )}
                <Link to="/branches" className={buttonClass()}>
                    <Icon name="x" />
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function BranchForm() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { data, error, reload } = useResource<Saved>(id ? `/branches/${id}` : '/branches/form');
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
            navigate(`/branches/${next.id}/edit`);
        }

        window.scrollTo(0, 0);
    };

    const remove = async () => {
        if (!(await confirm({ title: `Delete ${record!.name}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/branches/${record!.id}`);
            toast.success('Deleted');
            navigate('/branches');
        } catch (failure) {
            toast.error(errorMessage(failure));
            reload();
        }
    };

    return (
        <>
            <PageHeader
                title={id ? `Edit ${record?.name ?? 'branch'}` : 'Create branch'}
                actions={
                    <>
                        <Link to="/branches" className={buttonClass()}>
                            <Icon name="arrow-left" />
                            Branches
                        </Link>
                        {record?.siteUrl && (
                            <a href={record.siteUrl} target="_blank" rel="noreferrer" className={buttonClass()}>
                                <Icon name="external-link" />
                                View on the site
                            </a>
                        )}
                        {record?.canDelete && (
                            <Button icon="trash-2" variant="destructive" onClick={remove}>
                                Delete
                            </Button>
                        )}
                    </>
                }
            />

            {record && record.missing.length > 0 && (
                <Alert title="Not taking online bookings yet" tone="warning">
                    <ul className="m-0 list-disc ps-5">
                        {record.missing.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                </Alert>
            )}
            {record?.warnings.map((warning) => (
                <Alert key={warning.title} title={warning.title} tone="warning">
                    {warning.body}
                </Alert>
            ))}

            {error && <ErrorState message={error} onRetry={reload} />}

            {ready ? <Fields key={`${id ?? 'new'}-${round}`} record={record} start={ready.values} options={ready.options} onSaved={afterSave} /> : !error && <Loading rows={5} />}
        </>
    );
}
