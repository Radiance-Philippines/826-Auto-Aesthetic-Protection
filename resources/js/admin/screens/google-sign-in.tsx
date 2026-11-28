import { useState } from 'react';

import { api, errorMessage } from '../lib/api';
import { SecretField } from '../shell/SecretField';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { Button } from '../ui/Button';
import { confirm } from '../ui/Dialog';
import { TextField } from '../ui/Field';
import { toast } from '../ui/toast';
import { CopyButton, Section, useSubmit } from './bookings/parts';

type Name = 'clientId' | 'androidClientId' | 'iosClientId' | 'appleClientId';
type Settings = Record<Name, string | null> & { hasSecret: boolean; enabled: boolean; appEnabled: boolean; redirectUrl: string; said: { title: string; body: string | null } | null };

const appIds: { name: Name; label: string; hint: string; placeholder: string }[] = [
    { name: 'androidClientId', label: 'Google Android client ID', placeholder: '…apps.googleusercontent.com', hint: 'Google Cloud Console → Credentials → OAuth client ID → Android (package ph.radianceph.aap826 and the app’s signing certificate SHA-1).' },
    { name: 'iosClientId', label: 'Google iOS client ID', placeholder: '…apps.googleusercontent.com', hint: 'OAuth client ID → iOS (bundle ID ph.radianceph.aap826). Needed once the iPhone app exists.' },
    { name: 'appleClientId', label: 'Apple client ID', placeholder: 'ph.radianceph.aap826', hint: 'For Sign in with Apple: the app’s bundle ID (from your Apple Developer account). Needed for the iPhone app.' },
];

function say({ said }: Settings) {
    if (said) {
        toast.success(said.title, said.body ? { description: said.body } : undefined);
    }
}

function Form({ record, onSaved }: { record: Settings; onSaved: (record: Settings) => void }) {
    const [values, setValues] = useState<Record<Name, string>>({
        clientId: record.clientId ?? '',
        androidClientId: record.androidClientId ?? '',
        iosClientId: record.iosClientId ?? '',
        appleClientId: record.appleClientId ?? '',
    });
    const [clientSecret, setSecret] = useState('');
    const { errors, failed, busy, submit } = useSubmit();
    const text = (name: Name) => ({ value: values[name], error: errors[name], onValueChange: (value: string) => setValues((current) => ({ ...current, [name]: value })) });

    const done = (saved: Settings) => {
        say(saved);
        onSaved(saved);
    };

    const save = () => submit(async () => done(await api<Settings>('POST', '/google-sign-in', { ...values, clientSecret })));

    const turnOff = async () => {
        if (!(await confirm({ title: 'Turn off Google sign-in', description: 'Customers can’t reserve online until it’s set up again. Their accounts and bookings stay.', label: 'Confirm', icon: 'power' }))) {
            return;
        }

        try {
            done(await api<Settings>('POST', '/google-sign-in/turn-off'));
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    return (
        <SettingsForm
            onSubmit={save}
            errors={errors}
            failed={failed}
            busy={busy}
            extra={
                record.enabled && (
                    <Button type="button" icon="power" onClick={turnOff}>
                        Turn off Google sign-in
                    </Button>
                )
            }
        >
            <Section title={record.enabled ? 'On: customers sign in with Google to reserve' : 'Off: customers can’t reserve online yet'}>
                <SectionNote>
                    Make an OAuth client in Google Cloud Console → APIs & Services → Credentials → Create credentials → OAuth client ID → Web application. Add the redirect URI below, then paste the client ID and secret here.
                </SectionNote>
                <div className="space-y-4">
                    <div className="flex items-start gap-2 text-sm">
                        <p className="min-w-0 flex-1 break-all">
                            Authorised redirect URI: <span className="font-mono">{record.redirectUrl}</span>
                        </p>
                        <CopyButton text={record.redirectUrl} what="Redirect URI" />
                    </div>
                    <TextField label="Client ID" hint="From the OAuth client you made." placeholder="…apps.googleusercontent.com" maxLength={255} autoComplete="off" {...text('clientId')} />
                    <SecretField
                        label="Client secret"
                        hint={record.hasSecret ? 'Saved (hidden). Leave blank to keep it, or paste a new one.' : 'Stored encrypted. Never shown again after saving.'}
                        error={errors.clientSecret}
                        value={clientSecret}
                        onChange={setSecret}
                        maxLength={255}
                    />
                </div>
            </Section>
            <Section title={record.appEnabled ? 'Customer app sign-in: on' : 'Customer app sign-in: off'}>
                <SectionNote>
                    The 826 app signs customers in with their Google or Apple account. Each button shows in the app only once its client ID is saved here. The app checks these IDs, so they must match the ones the app was built with.
                </SectionNote>
                <div className="space-y-4">
                    {appIds.map((field) => (
                        <TextField key={field.name} label={field.label} hint={field.hint} placeholder={field.placeholder} maxLength={255} autoComplete="off" {...text(field.name)} />
                    ))}
                </div>
            </Section>
        </SettingsForm>
    );
}

export default function GoogleSignIn() {
    return (
        <SettingsPage<Settings> title="Google sign-in" path="/google-sign-in">
            {(record, onSaved, version) => <Form key={version} record={record} onSaved={onSaved} />}
        </SettingsPage>
    );
}
