import { useRouter } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { Pressable, View } from 'react-native';
import { ArrowLeft, Plus, Send } from 'lucide-react-native';
import { api, problemText, type HomeWindow, type SavedVehicle } from '@/api';
import { useAuth } from '@/context/auth';
import { useCatalog } from '@/context/catalog';
import { addDays, longDate, manilaToday, parseDate, isoDate } from '@/lib/dates';
import { unitName, unitSub } from '@/lib/unit';
import { daysLabel, emptyHomeDraft, emptyUnit, homeInput, servicesAt, validateHome, type Errors, type HomeDraft } from '@/lib/wizard';
import { radius, target } from '@/theme';
import { useTheme } from '@/context/theme';
import { Button } from '@/ui/Button';
import { Calendar } from '@/ui/Calendar';
import { Card } from '@/ui/Card';
import { Choice } from '@/ui/Choice';
import { Field } from '@/ui/Field';
import { Screen } from '@/ui/Screen';
import { Select } from '@/ui/Select';
import { Silhouette } from '@/ui/Silhouette';
import { Skeleton } from '@/ui/Skeleton';
import { ErrorState, FormError } from '@/ui/States';
import { Text } from '@/ui/Text';
import { UnitFields } from '@/ui/UnitFields';


const ANSWERS: { key: string; value: boolean | null; label: string }[] = [
    { key: 'yes', value: true, label: 'Yes' },
    { key: 'no', value: false, label: 'No' },
];

/** Home service: a request, not a booked date. The branch calls or texts to agree on a time. */
export default function HomeServiceScreen() {
    const c = useTheme();
    const router = useRouter();
    const { guest } = useAuth();
    const { catalog, error, reload } = useCatalog();
    const [draft, setDraft] = useState<HomeDraft>(() => ({ ...emptyHomeDraft(), name: guest?.name ?? '', phone: guest?.phone ?? '', email: guest?.email ?? '' }));
    const [errors, setErrors] = useState<Errors>({});
    const [saved, setSaved] = useState<SavedVehicle[] | null>(null);
    const [sending, setSending] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [sent, setSent] = useState<'booking' | 'demo' | null>(null);
    const patch = (changes: Partial<HomeDraft>) => setDraft((d) => ({ ...d, ...changes }));

    const branches = useMemo(() => (catalog?.branches ?? []).filter((b) => b.homeService.offered), [catalog]);
    const branch = branches.find((b) => b.id === draft.branchId);
    const services = servicesAt(catalog?.categories ?? [], branch, true);
    const tomorrow = isoDate(addDays(parseDate(manilaToday()), 1));

    useEffect(() => {
        void api.vehicles().then((result) => {
            const list = result.kind === 'ok' ? result.data.vehicles : [];
            setSaved(list);
            if (list.length) setDraft((d) => ({ ...d, unit: { ...emptyUnit(), mode: 'saved', vehicleId: null } }));
        });
    }, []);

    const submit = async () => {
        const found = validateHome(draft, branch);

        if (Object.keys(found).length) {
            setErrors(found);
            setProblem('Check the highlighted fields.');

            return;
        }

        setSending(true);
        setProblem(null);
        const result = await api.requestHome(homeInput(draft));
        setSending(false);

        if (result.kind === 'ok') {
            if (result.data.booking) router.replace(`/booking/${result.data.booking.id}`);
            else setSent('demo');

            return;
        }

        if (result.kind === 'invalid') {
            setErrors(Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key.replace('preferred_', '').replace('vehicle_', '').replace('plate_number', 'plate'), messages[0]])));
        }

        setProblem(problemText(result));
    };

    if (!catalog) {
        return (
            <Screen back="Book" title="Home service">
                {error ? <ErrorState result={error} onRetry={reload} /> : <Skeleton height={200} />}
            </Screen>
        );
    }

    if (sent === 'demo') {
        return (
            <Screen back="Book" title="Home service">
                <Card style={{ gap: 10 }}>
                    <Text v="title">That would be sent</Text>
                    <Text color={c.muted}>On the live site, the branch would now get your request and call or text you. This demo saves nothing.</Text>
                    <Button icon={ArrowLeft} label="Back to Book" onPress={() => router.back()} />
                </Card>
            </Screen>
        );
    }

    return (
        <Screen back="Book" title="Home service" footer={<Button icon={Send} label="Send request" loading={sending} onPress={submit} testID="send-request" />}>
            <Text color={c.muted}>Tell us where the unit will be and what you’d like done. The branch will call or text you to agree on a time, so this is a request, not a booked date yet.</Text>
            <FormError>{problem}</FormError>

            {branches.length === 0 ? (
                <Card>
                    <Text color={c.muted}>No branch offers home service right now. You can still reserve a date at a branch.</Text>
                </Card>
            ) : (
                <>
                    <Text v="label">Which branch should come?</Text>
                    <View style={{ gap: 10 }}>
                        {branches.map((b) => (
                            <Choice key={b.id} selected={draft.branchId === b.id} onPress={() => patch({ branchId: b.id, serviceIds: [], area: '' })} title={b.name} sub={b.homeService.areas.length ? b.homeService.areas.join(', ') : null} />
                        ))}
                        <FormError>{errors.branch}</FormError>
                    </View>

                    <Text v="label">Your unit</Text>
                    <View style={{ gap: 10 }}>
                        {saved?.map((v) => (
                            <Choice key={v.id} selected={draft.unit.mode === 'saved' && draft.unit.vehicleId === v.id} onPress={() => patch({ unit: { ...emptyUnit(), mode: 'saved', vehicleId: v.id } })} title={unitName(v)} sub={unitSub({ type: v.type, plate: v.plate })} left={<Silhouette type={v.type} width={56} height={34} />} />
                        ))}
                        <FormError>{errors.vehicle}</FormError>
                        {saved?.length && draft.unit.mode === 'saved' ? <Button kind="secondary" icon={Plus} label="New unit" onPress={() => patch({ unit: { ...emptyUnit(), mode: 'new' } })} /> : null}
                        {draft.unit.mode === 'new' || saved?.length === 0 ? <UnitFields unit={{ ...draft.unit, mode: 'new' }} types={catalog.vehicleTypes} errors={errors} onChange={(unit) => patch({ unit })} /> : null}
                    </View>

                    <Text v="label">Services</Text>
                    <View style={{ gap: 10 }}>
                        <Text v="small">Only services that can be done outside the shop are listed.</Text>
                        {!branch ? <Text color={c.muted}>Pick the branch first.</Text> : services.length === 0 ? <Text color={c.muted}>This branch has nothing to do at home. Try another branch, or reserve a date at the shop.</Text> : null}
                        {services.map((s) => (
                            <Choice key={s.id} kind="box" selected={draft.serviceIds.includes(s.id)} onPress={() => patch({ serviceIds: draft.serviceIds.includes(s.id) ? draft.serviceIds.filter((id) => id !== s.id) : [...draft.serviceIds, s.id] })} title={s.name} sub={s.estimateDays ? `About ${daysLabel(s.estimateDays).replace('about ', '')} of work` : null} />
                        ))}
                        <FormError>{errors.services}</FormError>
                        <Text v="small">Price depends on your unit. We confirm it after we review it.</Text>
                    </View>

                    <Text v="label">When suits you?</Text>
                    <View style={{ gap: 10 }}>
                        <Text v="small">The branch confirms the exact day and time with you.</Text>
                        {branch ? <Calendar range={{ first: tomorrow, last: branch.lastDay }} value={draft.date} onPick={(date) => patch({ date })} /> : <Text color={c.muted}>Pick the branch first.</Text>}
                        {draft.date ? <Text weight="semi">{longDate(draft.date)}</Text> : null}
                        <FormError>{errors.date}</FormError>
                        <View accessibilityRole="radiogroup" style={{ flexDirection: 'row', gap: 8, flexWrap: 'wrap' }}>
                            {(Object.entries(catalog.booking.windows) as [HomeWindow, string][]).map(([key, label]) => (
                                <Pressable key={key} accessibilityRole="radio" accessibilityState={{ selected: draft.window === key }} onPress={() => patch({ window: key })} style={{ minHeight: target, paddingHorizontal: 16, borderRadius: radius.pill, borderWidth: 1, borderColor: draft.window === key ? c.accent : c.line, backgroundColor: draft.window === key ? c.accentSoft : c.surface, justifyContent: 'center' }}>
                                    <Text weight="semi" color={draft.window === key ? c.accent : c.text}>
                                        {label}
                                    </Text>
                                </Pressable>
                            ))}
                        </View>
                        <FormError>{errors.window}</FormError>
                    </View>

                    <Text v="label">Where’s the unit?</Text>
                    <View style={{ gap: 14 }}>
                        <Field label="Address" multiline value={draft.address} onChangeText={(address) => patch({ address })} hint="House number, street, barangay and city" error={errors.address} />
                        {branch && branch.homeService.areas.length ? <Select label="Area" value={draft.area} options={[...branch.homeService.areas, 'Other'].map((a) => ({ value: a, label: a }))} onChange={(area) => patch({ area })} placeholder="Pick your area" error={errors.area} /> : null}
                        {draft.area === 'Other' ? <Field label="Your area" value={draft.areaOther} onChangeText={(areaOther) => patch({ areaOther })} hint="City or municipality" error={errors.areaOther} /> : null}
                        <Field label="Landmark" optional value={draft.landmark} onChangeText={(landmark) => patch({ landmark })} hint="Something near you that’s easy to spot" />
                    </View>

                    <Text v="label">What’s there?</Text>
                    <View style={{ gap: 14 }}>
                        <Text v="small">It helps the team bring the right gear.</Text>
                        {([['water', 'Is there a water tap they can use?'], ['power', 'Is there a power outlet they can use?'], ['shade', 'Is there a covered or shaded spot for the unit?']] as const).map(([key, question]) => (
                            <View key={key} style={{ gap: 6 }}>
                                <Text style={{ fontSize: 14 }}>{question}</Text>
                                <View accessibilityRole="radiogroup" style={{ flexDirection: 'row', gap: 8 }}>
                                    {ANSWERS.map((a) => (
                                        <Pressable key={a.key} accessibilityRole="radio" accessibilityLabel={`${question} ${a.label}`} accessibilityState={{ selected: draft[key] === a.value }} onPress={() => patch({ [key]: a.value })} style={{ minHeight: target, minWidth: 72, alignItems: 'center', justifyContent: 'center', borderRadius: radius.pill, borderWidth: 1, borderColor: draft[key] === a.value ? c.accent : c.line, backgroundColor: draft[key] === a.value ? c.accentSoft : c.surface }}>
                                            <Text weight="semi" color={draft[key] === a.value ? c.accent : c.text}>
                                                {a.label}
                                            </Text>
                                        </Pressable>
                                    ))}
                                </View>
                            </View>
                        ))}
                    </View>

                    <Text v="label">Your details</Text>
                    <View style={{ gap: 14 }}>
                        <Field label="Full name" value={draft.name} onChangeText={(name) => patch({ name })} autoCapitalize="words" error={errors.name} />
                        <Field label="Mobile number" value={draft.phone} onChangeText={(phone) => patch({ phone })} keyboardType="phone-pad" placeholder="0917 123 4567" error={errors.phone} />
                        <Field label="Email" optional value={draft.email} onChangeText={(email) => patch({ email })} keyboardType="email-address" autoCapitalize="none" error={errors.email} />
                        <Field label="Anything we should know?" optional multiline value={draft.message} onChangeText={(message) => patch({ message })} />
                        <Choice plain kind="box" selected={draft.consent} onPress={() => patch({ consent: !draft.consent })} title="I agree that 826 may use my name, number, email and vehicle details to handle this booking, as the Data Privacy Act of 2012 allows." />
                        <FormError>{errors.consent}</FormError>
                        <Text v="small">Nothing is charged here. The branch will call or text you to confirm.</Text>
                    </View>
                </>
            )}
        </Screen>
    );
}
