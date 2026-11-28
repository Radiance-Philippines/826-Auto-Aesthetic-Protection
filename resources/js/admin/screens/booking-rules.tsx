import { useState } from 'react';

import { api } from '../lib/api';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { SwitchField } from '../ui/Field';
import { NumberField } from '../ui/NumberField';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Section, useSubmit } from './bookings/parts';

type Rules = {
    cancelCutoffHours: number | null;
    rescheduleEnabled: boolean;
    rescheduleCutoffHours: number | null;
    maxReschedules: number | null;
    maxUnpaidHoldsPerGuest: number | null;
    maxUpcomingPerGuest: number | null;
    policyText: string | null;
};

function Form({ record, onSaved }: { record: Rules; onSaved: (record: Rules) => void }) {
    const [values, setValues] = useState(record);
    const { errors, failed, busy, submit } = useSubmit();
    const set = <Key extends keyof Rules>(key: Key) => (value: Rules[Key]) => setValues((current) => ({ ...current, [key]: value }));

    const save = () =>
        submit(async () => {
            const saved = await api<Rules>('POST', '/booking-rules', values);
            toast.success('Booking rules saved');
            onSaved(saved);
        });

    return (
        <SettingsForm onSubmit={save} errors={errors} failed={failed} busy={busy} saveLabel="Save rules">
            <Section title="Cancelling and moving">
                <SectionNote>What customers can do themselves in My bookings. The team can always cancel or move a booking from the admin.</SectionNote>
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    <NumberField label="Cancel online up to" suffix="hours before drop-off" hint="Hours before drop-off, 0 to 720." error={errors.cancelCutoffHours} value={values.cancelCutoffHours} onChange={set('cancelCutoffHours')} min={0} />
                    <SwitchField label="Customers can move their booking" hint="Off: only the team moves a booking." checked={values.rescheduleEnabled} onChange={set('rescheduleEnabled')} />
                    {values.rescheduleEnabled && (
                        <>
                            <NumberField label="Move online up to" suffix="hours before drop-off" hint="Hours before drop-off, 0 to 720." error={errors.rescheduleCutoffHours} value={values.rescheduleCutoffHours} onChange={set('rescheduleCutoffHours')} min={0} />
                            <NumberField label="Times a booking can be moved" hint="From 1 to 10." error={errors.maxReschedules} value={values.maxReschedules} onChange={set('maxReschedules')} min={1} />
                        </>
                    )}
                </div>
            </Section>
            <Section title="Per customer">
                <SectionNote>Keeps one Google account from holding every day. Set 0 for no limit.</SectionNote>
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    <NumberField label="Unpaid holds at a time" hint="From 0 to 20." error={errors.maxUnpaidHoldsPerGuest} value={values.maxUnpaidHoldsPerGuest} onChange={set('maxUnpaidHoldsPerGuest')} min={0} />
                    <NumberField label="Upcoming bookings at a time" hint="From 0 to 50." error={errors.maxUpcomingPerGuest} value={values.maxUpcomingPerGuest} onChange={set('maxUpcomingPerGuest')} min={0} />
                </div>
            </Section>
            <Section title="Terms">
                <TextAreaField
                    label="Shown before customers reserve"
                    hint="Your own terms, in your words. Leave blank to show none."
                    error={errors.policyText}
                    value={values.policyText ?? ''}
                    onValueChange={set('policyText')}
                    rows={6}
                    maxLength={4000}
                    placeholder="Refunds of the reservation fee, late arrivals, pickup…"
                />
            </Section>
        </SettingsForm>
    );
}

export default function BookingRules() {
    return (
        <SettingsPage<Rules> title="Booking rules" path="/booking-rules">
            {(record, onSaved, version) => <Form key={version} record={record} onSaved={onSaved} />}
        </SettingsPage>
    );
}
