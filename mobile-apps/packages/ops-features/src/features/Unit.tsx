import { Calendar as CalendarIcon, Camera, Image as ImageIcon, MessageSquarePlus, Pencil, Phone, Save, UserRound } from 'lucide-react-native';
import { Image } from 'expo-image';
import { useState } from 'react';
import { Linking, View } from 'react-native';
import { api, problemText, type UnitDetail } from '../api';
import { useUser } from '../context/auth';
import { useLoad, shortDate, shortStamp, stamp, peso, c, Button, Card, Chip, Field, Screen, Sheet, Skeleton, ErrorState, FormError, Text } from '@826/core';
import { canSeeContact, canSeeMoney } from '../lib/access';
import { pickPhoto, takePhoto } from '../lib/photo';
import { dayLine, stageChip, stepIcon } from '../lib/stage';
import { unitName, unitSub } from '../lib/unit';
import { Calendar } from '../ui/Calendar';
import { Confirm } from '../ui/Confirm';

/** The unit's steps with their times: done ones ticked, the current one filled (design S3). The server decides the steps (a home job has fewer). */
export function StageList({ unit }: { unit: UnitDetail }) {
    return (
        <View style={{ gap: 14 }} accessible accessibilityLabel={`Progress: ${stageChip(unit.stage).label}`}>
            {unit.timeline.map((row) => {
                const detail = row.current ? (dayLine(unit) ?? shortStamp(row.at)) : row.at ? shortStamp(row.at) : null;

                return (
                    <View key={row.key} style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                        <View style={{ width: 22, height: 22, borderRadius: 11, alignItems: 'center', justifyContent: 'center', backgroundColor: row.current ? c.accent : row.done ? 'rgba(123,224,160,0.18)' : c.surface2 }}>
                            {row.done && !row.current ? <Text color={c.tone.green.fg} style={{ fontSize: 13, lineHeight: 16 }}>✓</Text> : null}
                        </View>
                        <Text weight={row.current ? 'bold' : 'regular'} color={row.done || row.current ? c.text : c.field} style={{ flex: 1, fontSize: 16 }}>
                            {row.label}
                        </Text>
                        {detail ? (
                            <Text v="small" color={row.current ? c.accent : c.muted}>
                                {detail}
                            </Text>
                        ) : null}
                    </View>
                );
            })}
        </View>
    );
}

/** S3: one unit */
export function UnitScreen({ id }: { id: number }) {
    const user = useUser();
    const { data: unit, error, loading, reload } = useLoad(() => api.unit(id));
    const [current, setCurrent] = useState<UnitDetail | null>(null);
    const [sheet, setSheet] = useState<null | 'date' | 'photo' | 'note' | 'exit' | 'assign'>(null);
    const [who, setWho] = useState('');
    const [note, setNote] = useState('');
    const [date, setDate] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const shown = current ?? unit;

    /** Every write returns the fresh unit; it replaces what's on screen */
    const run = async (name: string, call: () => Promise<Awaited<ReturnType<typeof api.unit>>>, done?: () => void) => {
        setBusy(name);
        setProblem(null);
        const result = await call();
        setBusy(null);

        if (result.kind === 'ok') {
            setCurrent(result.data);
            setSheet(null);
            done?.();
        } else {
            setProblem(problemText(result));
        }
    };

    const addPhoto = async (take: boolean) => {
        const file = take ? await takePhoto() : await pickPhoto();
        if (!file) {
            setSheet(null);

            return;
        }
        await run('photo', () => api.addPhoto(id, file));
    };

    if (!shown) {
        return (
            <Screen back="Queue" onRefresh={reload}>
                {loading ? <Skeleton height={220} style={{ borderRadius: 20 }} /> : error ? <ErrorState result={error} onRetry={reload} /> : null}
            </Screen>
        );
    }

    const step = shown.can.advance ? shown.next : null;
    const stage = stageChip(shown.stage);
    const phone = canSeeContact(user) ? shown.customer.phone : null;
    const money = canSeeMoney(user) ? shown.money : null;

    return (
        <Screen
            back="Queue"
            onRefresh={async () => {
                setCurrent(null);
                await reload();
            }}
        >
            <Card style={{ gap: 10 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
                    <Text v="title" style={{ flex: 1, fontSize: 20 }}>
                        {unitName(shown.vehicle)}
                    </Text>
                    <Chip label={stage.label} tone={stage.tone} />
                </View>
                <Text color={c.muted}>{[unitSub(shown.vehicle), shown.customer.name].filter(Boolean).join(' · ')}</Text>
                {shown.services.length ? <Text>{shown.services.map((service) => service.name).join(', ')}</Text> : null}
                {shown.late ? <Chip label="Late" tone="red" /> : null}
                {phone ? <Button small kind="secondary" icon={Phone} label={`Call ${phone}`} onPress={() => void Linking.openURL(`tel:${phone.replace(/\s+/g, '')}`)} /> : null}
                {money ? (
                    <Text weight="semi">
                        {money.priced && typeof money.total === 'number' ? `Total ${peso(money.total)}` : 'Not priced yet'}
                        {typeof money.amountPaid === 'number' && money.amountPaid > 0 ? ` · paid ${peso(money.amountPaid)}` : ''}
                    </Text>
                ) : null}
            </Card>

            <StageList unit={shown} />
            <FormError>{problem}</FormError>
            {step ? <Button label={step.label} icon={stepIcon(step.to)} loading={busy === 'stage'} onPress={() => (step.confirm ? setSheet('exit') : void run('stage', () => api.setStage(id, step.to)))} /> : null}

            {shown.can.assign || shown.assignedTo ? (
                <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                    <UserRound size={22} color={c.gold} />
                    <View style={{ flex: 1 }}>
                        <Text v="small">Assigned to</Text>
                        <Text weight="bold">{shown.assignedTo ?? 'No one yet'}</Text>
                    </View>
                    {shown.can.assign ? (
                        <Button
                            small
                            kind="secondary"
                            icon={Pencil}
                            label={shown.assignedTo ? 'Change' : 'Assign'}
                            accessibilityLabel="Say who is on this unit"
                            onPress={() => {
                                setWho(shown.assignedTo ?? '');
                                setSheet('assign');
                            }}
                        />
                    ) : null}
                </Card>
            ) : null}

            <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <CalendarIcon size={22} color={c.gold} />
                <View style={{ flex: 1 }}>
                    <Text weight="bold">{[dayLine(shown), shown.readyOn ? `ready by ${shortDate(shown.readyOn)}` : 'no ready date yet'].filter(Boolean).join(' · ')}</Text>
                    <Text v="small">Estimate in days only. Sales get a heads-up when this changes.</Text>
                </View>
                {shown.can.changeReadyDate ? (
                    <Button
                        small
                        kind="secondary"
                        label="Change"
                        accessibilityLabel="Change the ready date"
                        onPress={() => {
                            setDate(shown.readyOn);
                            setSheet('date');
                        }}
                    />
                ) : null}
            </Card>

            {shown.can.addPhoto || shown.can.addNote ? (
                <View style={{ flexDirection: 'row', gap: 12 }}>
                    {shown.can.addPhoto ? <Button style={{ flex: 1 }} kind="secondary" icon={Camera} label="Add photo" loading={busy === 'photo'} onPress={() => setSheet('photo')} /> : null}
                    {shown.can.addNote ? <Button style={{ flex: 1 }} kind="secondary" icon={MessageSquarePlus} label="Add note" onPress={() => setSheet('note')} /> : null}
                </View>
            ) : null}

            {shown.photos.length ? (
                <View style={{ gap: 8 }}>
                    <Text v="label">Photos</Text>
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                        {shown.photos.map((photo) => (
                            <Image key={photo.id} source={{ uri: photo.thumb }} accessibilityLabel={`Photo added ${stamp(photo.at) ?? ''}`} style={{ width: 96, height: 96, borderRadius: 12, backgroundColor: c.surface2 }} contentFit="cover" />
                        ))}
                    </View>
                </View>
            ) : null}

            <View style={{ gap: 10 }}>
                <Text v="label">History</Text>
                {shown.history.length === 0 ? <Text color={c.muted}>Nothing yet.</Text> : null}
                {shown.history.map((event) => (
                    <View key={event.id} style={{ gap: 2 }}>
                        <Text>{event.kind === 'note' ? (event.note ?? event.line) : event.line}</Text>
                    </View>
                ))}
            </View>

            <Sheet open={sheet === 'date'} onClose={() => setSheet(null)} title="Ready date">
                <Calendar value={date} onPick={setDate} />
                <FormError>{problem}</FormError>
                <Button label="Save ready date" icon={Save} disabled={!date} loading={busy === 'date'} onPress={() => date && void run('date', () => api.setReadyDate(id, date))} />
            </Sheet>
            <Sheet open={sheet === 'photo'} onClose={() => setSheet(null)} title="Add photo">
                <Button label="Take a photo" icon={Camera} onPress={() => void addPhoto(true)} />
                <Button label="Choose from photos" kind="secondary" icon={ImageIcon} onPress={() => void addPhoto(false)} />
                <FormError>{problem}</FormError>
            </Sheet>
            <Sheet open={sheet === 'assign'} onClose={() => setSheet(null)} title="Who is on this unit?">
                <Field label="Assigned to" value={who} onChangeText={setWho} maxLength={120} placeholder="Jun, Ben" hint="Type the name or names. Leave blank to clear." />
                <FormError>{problem}</FormError>
                <Button label="Save" icon={Save} loading={busy === 'assign'} onPress={() => void run('assign', () => api.setAssignedTo(id, who.trim()))} />
            </Sheet>
            <Sheet open={sheet === 'note'} onClose={() => setSheet(null)} title="Add note">
                <Field label="Note" value={note} onChangeText={setNote} multiline maxLength={500} hint="Shows in this unit’s history. 500 characters at most." />
                <FormError>{problem}</FormError>
                <Button
                    label="Save note"
                    icon={Save}
                    disabled={!note.trim()}
                    loading={busy === 'note'}
                    onPress={() => void run('note', () => api.addNote(id, note.trim()), () => setNote(''))}
                />
            </Sheet>
            <Confirm open={sheet === 'exit'} title={`${shown.next?.label ?? 'Confirm'}?`} body={`${unitName(shown.vehicle)}: ${shown.next?.label?.toLowerCase()}. This can’t be undone from the app.`} action={shown.next?.label ?? 'Confirm'} busy={busy === 'stage'} problem={problem} onConfirm={() => shown.next && void run('stage', () => api.setStage(id, shown.next!.to))} onClose={() => setSheet(null)} />
        </Screen>
    );
}
