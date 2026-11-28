import { useState } from 'react';

import { api } from '../lib/api';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { SwitchField } from '../ui/Field';
import { toast } from '../ui/toast';
import { Section, useSubmit } from './bookings/parts';

type Settings = { requireTwoFactor: boolean; allowPasskeys: boolean };

function Form({ record, onSaved }: { record: Settings; onSaved: (record: Settings) => void }) {
    const [values, setValues] = useState(record);
    const { errors, failed, busy, submit } = useSubmit();

    const save = () =>
        submit(async () => {
            const saved = await api<Settings>('POST', '/security', values);
            toast.success('Security saved');
            onSaved(saved);
        });

    return (
        <SettingsForm onSubmit={save} errors={errors} failed={failed} busy={busy} saveLabel="Save security">
            <Section title="Two-factor sign-in">
                <SectionNote>Each person can turn on an authenticator app (with recovery codes) or an emailed code from their profile, under the user menu.</SectionNote>
                <SwitchField
                    label="Require two-factor sign-in for everyone"
                    hint="When on, anyone without it is sent to set it up before they can use the admin. A passkey counts too (while passkey sign-in is allowed below), so someone with a passkey is not asked for the app. The email code only works once email is set up in Email settings, so the app is the safer choice."
                    checked={values.requireTwoFactor}
                    onChange={(requireTwoFactor) => setValues((current) => ({ ...current, requireTwoFactor }))}
                />
            </Section>
            <Section title="Passkeys">
                <SectionNote>Each person adds passkeys from their profile, under the user menu. A passkey sign-in checks their fingerprint, face or PIN, so it does not ask for the 2FA code. Passwords keep working with the 2FA rules above.</SectionNote>
                <SwitchField
                    label="Allow passkey sign-in"
                    hint="When off, the “Sign in with a passkey” button is hidden and passkey sign-in stops working. Passkeys people already added are kept."
                    checked={values.allowPasskeys}
                    onChange={(allowPasskeys) => setValues((current) => ({ ...current, allowPasskeys }))}
                />
            </Section>
        </SettingsForm>
    );
}

export default function Security() {
    return (
        <SettingsPage<Settings> title="Security" path="/security">
            {(record, onSaved, version) => <Form key={version} record={record} onSaved={onSaved} />}
        </SettingsPage>
    );
}
