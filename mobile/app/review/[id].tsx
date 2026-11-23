import { Image } from 'expo-image';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Check, Info, Plus, Send, X } from 'lucide-react-native';
import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { api, problemText, type CustomerBooking, type UploadFile } from '@/api';
import { useLoad } from '@/hooks/useLoad';
import { dayMonthYear } from '@/lib/dates';
import { pickPhoto } from '@/lib/photo';
import { reviewProblems } from '@/lib/wizard';
import { bookingUnit, serviceLine } from '@/lib/unit';
import { radius, target } from '@/theme';
import { useTheme } from '@/context/theme';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Field } from '@/ui/Field';
import { Screen } from '@/ui/Screen';
import { Silhouette } from '@/ui/Silhouette';
import { Skeleton } from '@/ui/Skeleton';
import { ErrorState, FormError } from '@/ui/States';
import { Stars } from '@/ui/Stars';
import { Text } from '@/ui/Text';

export default function ReviewScreen() {
    const c = useTheme();
    const router = useRouter();
    const { id } = useLocalSearchParams<{ id: string }>();
    const { data, error, loading, reload } = useLoad<CustomerBooking, { booking: CustomerBooking }>(() => api.booking(Number(id)), (raw) => raw.booking);
    const [rating, setRating] = useState(0);
    const [body, setBody] = useState('');
    const [photo, setPhoto] = useState<UploadFile | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [done, setDone] = useState(false);

    const submit = async () => {
        const found = reviewProblems(rating, body);
        setErrors(found);

        if (Object.keys(found).length || !data) return;

        setBusy(true);
        setProblem(null);
        const result = await api.review(data.id, rating, body, photo);
        setBusy(false);

        if (result.kind === 'ok') setDone(true);
        else if (result.kind === 'invalid') setErrors(Object.fromEntries(Object.entries(result.errors).map(([k, m]) => [k, m[0]])));
        else setProblem(problemText(result));
    };

    return (
        <Screen back="Back" title="Write a review" onRefresh={reload} footer={!done && data ? <Button icon={Send} label="Post review" loading={busy} onPress={submit} testID="post-review" /> : undefined}>
            {loading ? (
                <Skeleton height={200} />
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : done ? (
                <Card style={{ gap: 10 }}>
                    <Text v="title">Thanks.</Text>
                    <Text color={c.muted}>We’ll show it once our team has read it.</Text>
                    <Button icon={Check} label="Done" onPress={() => router.back()} />
                </Card>
            ) : data ? (
                <>
                    <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12 }}>
                        <Silhouette type={data.vehicle.type} width={64} height={40} />
                        <View style={{ flex: 1 }}>
                            <Text weight="bold">{bookingUnit(data).name}</Text>
                            <Text v="small">{[serviceLine(data), data.branch?.name, data.exitedAt ? dayMonthYear(data.exitedAt.slice(0, 10)) : null].filter(Boolean).join(' · ')}</Text>
                        </View>
                    </Card>
                    <FormError>{problem}</FormError>

                    <Text v="label">Your rating</Text>
                    <Stars value={rating} onChange={setRating} />
                    <FormError>{errors.rating}</FormError>

                    <Field label="Your review" multiline value={body} onChangeText={setBody} maxLength={1000} hint="At least 10 characters." error={errors.body} />

                    <Text v="label">Photo</Text>
                    <View style={{ flexDirection: 'row', gap: 12 }}>
                        {photo ? (
                            <View>
                                <Image source={{ uri: photo.uri }} accessibilityLabel="The photo you picked" style={{ width: 96, height: 96, borderRadius: radius.md }} />
                                <Pressable accessibilityRole="button" accessibilityLabel="Remove photo" onPress={() => setPhoto(null)} style={{ position: 'absolute', top: -8, right: -8, width: 32, height: 32, borderRadius: 16, backgroundColor: c.surface2, borderWidth: 1, borderColor: c.line, alignItems: 'center', justifyContent: 'center' }}>
                                    <X size={16} color={c.text} />
                                </Pressable>
                            </View>
                        ) : (
                            <Pressable accessibilityRole="button" accessibilityLabel="Add a photo" onPress={async () => setPhoto((await pickPhoto()) ?? photo)} style={{ width: 96, height: 96, minWidth: target, borderRadius: radius.md, borderWidth: 1, borderColor: c.field, alignItems: 'center', justifyContent: 'center', backgroundColor: c.surface }}>
                                <Plus size={26} color={c.accent} />
                            </Pressable>
                        )}
                    </View>
                    <Text v="small">Optional. JPG, PNG or WebP.</Text>

                    <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>
                        <Info size={16} color={c.muted} />
                        <Text v="small" style={{ flex: 1 }}>
                            826 checks reviews before they go on the website.
                        </Text>
                    </View>
                </>
            ) : null}
        </Screen>
    );
}
