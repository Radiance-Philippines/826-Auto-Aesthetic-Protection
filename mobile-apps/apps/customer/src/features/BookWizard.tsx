import { useLocalSearchParams, useRouter } from 'expo-router';
import { ArrowLeft, ArrowRight, CalendarCheck, FileText, Home, MapPin, Pencil, Plus, RotateCcw } from 'lucide-react-native';
import { useEffect, useMemo, useState } from 'react';
import { View } from 'react-native';
import { api, problemText, type Branch, type MonthAvailability, type SavedVehicle } from '@/api';
import { useAuth } from '@/context/auth';
import { useCatalog } from '@/context/catalog';
import { longDate, useTheme, Button, Card, Field, Screen, Sheet, Skeleton, ErrorState, FormError, Text, Chip } from '@826/core';
import { unitName, unitSub } from '@/lib/unit';
import { daysLabel, emptyDraft, emptyUnit, readyOn, reservationInput, servicesAt, STEP_TITLES, STEPS, validateStep, type Draft, type Errors, type Step } from '@/lib/wizard';
import { Calendar } from '@/ui/Calendar';
import { Choice } from '@/ui/Choice';
import { Silhouette } from '@/ui/Silhouette';
import { UnitFields } from '@/ui/UnitFields';

const FIELD_STEP: Record<string, Step> = { branch: 'branch', vehicle_id: 'unit', vehicle_year: 'unit', vehicle_model: 'unit', vehicle_type: 'unit', plate_number: 'unit', services: 'services', date: 'date' };

export function BookWizard() {
    const c = useTheme();
    const router = useRouter();
    const { vehicleId } = useLocalSearchParams<{ vehicleId?: string }>();
    const { guest } = useAuth();
    const { catalog, error: catalogError, reload } = useCatalog();
    const [step, setStep] = useState<Step>('branch');
    const [draft, setDraft] = useState<Draft>(() => ({ ...emptyDraft(), name: guest?.name ?? '', phone: guest?.phone ?? '', email: guest?.email ?? '' }));
    const [errors, setErrors] = useState<Errors>({});
    const [saved, setSaved] = useState<SavedVehicle[] | null>(null);
    const [months, setMonths] = useState<Record<string, MonthAvailability>>({});
    const [sending, setSending] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [demoDone, setDemoDone] = useState<string | null>(null);
    const [policyOpen, setPolicyOpen] = useState(false);

    const branches = useMemo(() => catalog?.branches ?? [], [catalog]);
    const branch = branches.find((b) => b.id === draft.branchId);
    const services = servicesAt(catalog?.categories ?? [], branch);
    const picked = services.filter((s) => draft.serviceIds.includes(s.id));
    const at = STEPS.indexOf(step);
    const patch = (changes: Partial<Draft>) => setDraft((d) => ({ ...d, ...changes }));

    useEffect(() => {
        let alive = true;
        void api.vehicles().then((result) => {
            if (!alive) return;
            const list = result.kind === 'ok' ? result.data.vehicles : [];
            setSaved(list);
            // "Book again" from the garage, or the only saved unit's first pick
            const wanted = list.find((v) => String(v.id) === vehicleId);

            if (wanted) patch({ unit: { ...emptyUnit(), mode: 'saved', vehicleId: wanted.id } });
            else if (list.length) setDraft((d) => (d.unit.vehicleId ? d : { ...d, unit: { ...emptyUnit(), mode: 'saved', vehicleId: null } }));
        });

        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [vehicleId]);

    // A single bookable branch is the answer to step one
    useEffect(() => {
        const bookable = branches.filter((b) => b.bookable);

        if (bookable.length === 1 && draft.branchId === null) patch({ branchId: bookable[0].id });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [branches]);

    const estimate = Object.values(months).find((m) => m.estimate)?.estimate ?? null;
    const ready = draft.date && estimate ? readyOn(draft.date, estimate.days, months, branch?.hours) : null;

    const go = (to: Step) => {
        setErrors({});
        setProblem(null);
        setStep(to);
    };

    const next = () => {
        const found = validateStep(step, draft);

        if (Object.keys(found).length) {
            setErrors(found);

            return;
        }

        go(STEPS[at + 1]);
    };

    const submit = async () => {
        setSending(true);
        setProblem(null);
        const result = await api.reserve(reservationInput(draft));
        setSending(false);

        if (result.kind === 'ok') {
            if (result.data.booking) {
                // The tab stays mounted: start the next booking from scratch
                setDraft({ ...emptyDraft(), name: draft.name, phone: draft.phone, email: draft.email, branchId: draft.branchId });
                setStep('branch');
                router.push(`/booking/${result.data.booking.id}`);
            } else {
                setDemoDone(result.data.readyOn ? `Ready around ${longDate(result.data.readyOn)}` : '');
            }

            return;
        }

        if (result.kind === 'invalid') {
            const field = Object.keys(result.errors)[0];
            const target = FIELD_STEP[field] ?? 'details';
            setErrors(Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key.replace('vehicle_', '').replace('plate_number', 'plate').replace('referral_code', 'referral'), messages[0]])));
            setProblem(problemText(result));
            setStep(target);

            return;
        }

        if (result.kind === 'conflict') {
            setProblem(result.message ?? 'Pick another date from the calendar.');
            setStep('date');

            return;
        }

        setProblem(problemText(result));
    };

    if (!catalog) {
        return (
            <Screen brand title="Book a service" tabs>
                {catalogError ? <ErrorState result={catalogError} onRetry={reload} /> : <Skeleton height={220} />}
            </Screen>
        );
    }

    if (demoDone !== null) {
        return (
            <Screen brand title="Book a service" tabs>
                <Card style={{ gap: 10 }}>
                    <Text v="title">That would be booked</Text>
                    <Text color={c.muted}>On the live site, your date would now be reserved{demoDone ? ` (${demoDone.charAt(0).toLowerCase()}${demoDone.slice(1)})` : ''} and you’d land on your booking page. This demo saves nothing.</Text>
                    <Button
                        icon={RotateCcw}
                        label="Start over"
                        onPress={() => {
                            setDemoDone(null);
                            setDraft(emptyDraft());
                            go('branch');
                        }}
                    />
                </Card>
            </Screen>
        );
    }

    const referral = catalog.booking.referral;
    const savedPicked = saved?.find((v) => v.id === draft.unit.vehicleId);
    const unitLine = draft.unit.mode === 'saved' && savedPicked ? unitName(savedPicked) : [draft.unit.year, draft.unit.model].filter(Boolean).join(' ');
    const homeBranch = branches.some((b: Branch) => b.homeService.offered);

    const footer = (
        <View style={{ flexDirection: 'row', gap: 12 }}>
            {at > 0 ? <Button icon={ArrowLeft} label="Back" kind="secondary" onPress={() => go(STEPS[at - 1])} style={{ flex: 1 }} /> : null}
            {step === 'review' ? <Button icon={CalendarCheck} label="Reserve this date" loading={sending} onPress={submit} style={{ flex: 2 }} testID="reserve" /> : <Button icon={ArrowRight} label="Continue" onPress={next} style={{ flex: 2 }} testID="continue" />}
        </View>
    );

    return (
        <Screen brand title="Book a service" right={<Text v="small">{`Step ${at + 1} of ${STEPS.length}`}</Text>} tabs footer={footer}>
            <Text v="label" accessibilityRole="header">
                {STEP_TITLES[step]}
            </Text>
            <FormError>{problem}</FormError>

            {step === 'branch' ? (
                <View style={{ gap: 10 }}>
                    <Text color={c.muted}>Pick the shop you’ll drive to.</Text>
                    {branches.length === 0 || !branches.some((b) => b.bookable) ? <Text color={c.muted}>No branch takes online bookings yet. Call or message us to book.</Text> : null}
                    {branches.map((b) => (
                        <Choice
                            key={b.id}
                            selected={draft.branchId === b.id}
                            disabled={!b.bookable}
                            onPress={() => patch({ branchId: b.id, serviceIds: draft.branchId === b.id ? draft.serviceIds : [], date: null })}
                            title={b.name}
                            sub={b.status === 'coming_soon' ? 'Coming soon' : !b.bookable ? 'Book by call or message' : (b.address ?? b.city)}
                            left={<MapPin size={18} color={c.muted} />}
                            testID={`branch-${b.id}`}
                        />
                    ))}
                    <FormError>{errors.branch}</FormError>
                    {homeBranch ? <Button kind="link" icon={Home} label="Rather have us come to you? Request home service" onPress={() => router.push('/home-service')} /> : null}
                </View>
            ) : null}

            {step === 'unit' ? (
                <View style={{ gap: 10 }}>
                    <Text color={c.muted}>The year, model and type help the team know what to expect. We review your unit’s size after you book, then send the price.</Text>
                    {saved === null ? <Skeleton height={64} /> : null}
                    {saved?.map((v) => (
                        <Choice key={v.id} selected={draft.unit.mode === 'saved' && draft.unit.vehicleId === v.id} onPress={() => patch({ unit: { ...emptyUnit(), mode: 'saved', vehicleId: v.id } })} title={unitName(v)} sub={unitSub({ type: v.type, plate: v.plate })} left={<Silhouette type={v.type} width={56} height={34} />} />
                    ))}
                    <FormError>{errors.vehicle}</FormError>
                    {saved?.length ? (
                        draft.unit.mode === 'new' ? null : <Button kind="secondary" icon={Plus} label="Add another unit" onPress={() => patch({ unit: { ...emptyUnit(), mode: 'new' } })} />
                    ) : null}
                    {draft.unit.mode === 'new' || saved?.length === 0 ? (
                        <Card style={{ gap: 14 }}>
                            <Text v="title">Add a unit</Text>
                            <UnitFields unit={{ ...draft.unit, mode: 'new' }} types={catalog.vehicleTypes} errors={errors} onChange={(unit) => patch({ unit })} />
                        </Card>
                    ) : null}
                </View>
            ) : null}

            {step === 'services' ? (
                <View style={{ gap: 10 }}>
                    <Text color={c.muted}>{`Services at ${branch?.name ?? 'this branch'}. Pick one or more. Each shows about how long it takes.`}</Text>
                    {services.length === 0 ? <Text color={c.muted}>{`${branch?.name ?? 'This branch'} has no services to book online yet. Call or message the branch.`}</Text> : null}
                    {services.map((s) => (
                        <Choice
                            key={s.id}
                            kind="box"
                            selected={draft.serviceIds.includes(s.id)}
                            onPress={() => patch({ serviceIds: draft.serviceIds.includes(s.id) ? draft.serviceIds.filter((id) => id !== s.id) : [...draft.serviceIds, s.id], date: null })}
                            title={s.name}
                            sub={s.estimateDays ? `Takes ${daysLabel(s.estimateDays)}` : (s.summary ?? null)}
                            testID={`service-${s.id}`}
                        />
                    ))}
                    <FormError>{errors.services}</FormError>
                    <Text v="small">Price depends on your unit. We confirm it after we review it.</Text>
                </View>
            ) : null}

            {step === 'date' && branch ? (
                <View style={{ gap: 12 }}>
                    <Text color={c.muted}>{estimate ? `Your unit stays ${estimate.label}. Days with room are open.` : 'Days with room are open.'}</Text>
                    <Calendar job={{ kind: 'new', branch: branch.id, services: draft.serviceIds }} value={draft.date} onPick={(date) => patch({ date })} onMonths={setMonths} />
                    <FormError>{errors.date}</FormError>
                    {draft.date ? (
                        <Card style={{ gap: 4 }}>
                            <Text weight="bold">Drop-off {longDate(draft.date)}</Text>
                            <Text v="small">{ready ? `Ready around ${longDate(ready)}` : 'We confirm the ready date once you’ve booked.'}</Text>
                        </Card>
                    ) : null}
                </View>
            ) : null}

            {step === 'details' ? (
                <View style={{ gap: 14 }}>
                    <Text color={c.muted}>So the branch knows who to expect and how to reach you.</Text>
                    <Field label="Full name" value={draft.name} onChangeText={(name) => patch({ name })} autoCapitalize="words" textContentType="name" error={errors.name} />
                    <Field label="Mobile number" value={draft.phone} onChangeText={(phone) => patch({ phone })} keyboardType="phone-pad" textContentType="telephoneNumber" placeholder="0917 123 4567" hint="The branch texts or calls this number about your booking." error={errors.phone} />
                    <Field label="Email" optional value={draft.email} onChangeText={(email) => patch({ email })} keyboardType="email-address" autoCapitalize="none" textContentType="emailAddress" hint="For updates about your booking." error={errors.email} />
                    <Field label="Anything we should know?" optional multiline value={draft.message} onChangeText={(message) => patch({ message })} hint="Like scratches you want us to look at, or a preferred film brand." />
                    {referral.enabled ? (
                        <Field
                            label="Referral code"
                            optional
                            value={draft.referral}
                            onChangeText={(value) => patch({ referral: value.toUpperCase() })}
                            autoCapitalize="characters"
                            autoCorrect={false}
                            placeholder="JUAN-7K3Q"
                            hint={referral.discountLabel ? `A friend’s code gives you ${referral.discountLabel} on your first booking. Your discount shows once we review your unit.` : 'Your discount shows once we review your unit.'}
                            error={errors.referral}
                        />
                    ) : null}
                    <Choice
                        plain
                        kind="box"
                        selected={draft.consent}
                        onPress={() => patch({ consent: !draft.consent })}
                        title="I agree that 826 may use my name, number, email and vehicle details to handle this booking, as the Data Privacy Act of 2012 allows."
                        testID="consent"
                    />
                    <FormError>{errors.consent}</FormError>
                    {catalog.booking.policy ? <Button kind="link" icon={FileText} label="Booking policy" onPress={() => setPolicyOpen(true)} /> : null}
                    <Sheet open={policyOpen} onClose={() => setPolicyOpen(false)} title="Booking policy">
                        <Text>{catalog.booking.policy}</Text>
                    </Sheet>
                </View>
            ) : null}

            {step === 'review' ? (
                <View style={{ gap: 12 }}>
                    <Text color={c.muted}>Make sure everything is right. You can change any part.</Text>
                    <Card style={{ gap: 14 }}>
                        <Row label="Branch" value={branch?.name ?? ''} onChange={() => go('branch')} />
                        <Row label="Unit" value={unitLine} onChange={() => go('unit')} />
                        <Row label="Services" value={picked.map((s) => s.name).join(', ')} onChange={() => go('services')} />
                        <Row label="Drop-off" value={draft.date ? `${longDate(draft.date)}${ready ? ` · ready around ${longDate(ready)}` : ''}` : ''} onChange={() => go('date')} />
                        <Row label="Contact" value={[draft.name, draft.phone].filter(Boolean).join(' · ')} onChange={() => go('details')} />
                        {draft.referral ? <Row label="Referral code" value={draft.referral} onChange={() => go('details')} /> : null}
                    </Card>
                    <Text v="small">Price: we review your unit’s size, then send the price.</Text>
                    {catalog.booking.fee.enabled ? (
                        <Chip tone="gold" dot={false} label={catalog.booking.fee.label ? `Reservation fee: ${catalog.booking.fee.label}` : 'Reservation fee applies'} />
                    ) : null}
                    <Text v="small">{catalog.booking.fee.enabled ? `We hold your date for ${catalog.booking.fee.holdMinutes} minutes while you pay by GCash or Maya. You’ll pay the reservation fee on the next page.` : 'Nothing is charged here.'}</Text>
                </View>
            ) : null}
        </Screen>
    );
}

function Row({ label, value, onChange }: { label: string; value: string; onChange: () => void }) {
    return (
        <View style={{ flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }}>
            <View style={{ flex: 1, gap: 2 }}>
                <Text v="label">{label}</Text>
                <Text>{value}</Text>
            </View>
            <Button small kind="link" icon={Pencil} label="Change" accessibilityLabel={`Change ${label}`} onPress={onChange} />
        </View>
    );
}

