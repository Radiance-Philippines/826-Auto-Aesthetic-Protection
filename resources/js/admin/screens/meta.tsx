import { useEffect, useRef, useState } from 'react';

import { api, errorMessage } from '../lib/api';
import { SecretField } from '../shell/SecretField';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { Button } from '../ui/Button';
import { confirm } from '../ui/Dialog';
import { SwitchField, TextField } from '../ui/Field';
import { toast } from '../ui/toast';
import { CopyButton, Details, Entry, Section, useSubmit } from './bookings/parts';

type Settings = { appId: string | null; enabled: boolean; hasSecret: boolean; callbackUrl: string };

function Form({ record, token, onSaved }: { record: Settings; token: string | null; onSaved: (record: Settings) => void }) {
    const [appId, setAppId] = useState(record.appId ?? '');
    const [enabled, setEnabled] = useState(record.enabled);
    const [replace, setReplace] = useState(false);
    const [appSecret, setSecret] = useState('');
    const { errors, failed, busy, submit } = useSubmit();
    const typing = !record.hasSecret || replace;

    const save = () =>
        submit(async () => {
            const saved = await api<Settings>('POST', '/meta', { appId, enabled, appSecret: typing ? appSecret : null });
            toast.success('Meta settings saved');
            onSaved(saved);
        });

    return (
        <SettingsForm onSubmit={save} errors={errors} failed={failed} busy={busy}>
            <Section title="What to do in Meta">
                <SectionNote>Do this once. The owner does it, not the branches.</SectionNote>
                <ol className="m-0 list-decimal space-y-2 ps-5">
                    <li>
                        Go to <strong>developers.facebook.com</strong> → My Apps → Create app (type Business) and add the <strong>Messenger</strong> product.
                    </li>
                    <li>
                        App settings → Basic: copy the <strong>App ID</strong> and <strong>App secret</strong> into the fields below.
                    </li>
                    <li>
                        Messenger → Webhooks (or Webhooks → Page): paste the <strong>Callback URL</strong> and the <strong>Verify token</strong> from this page, then subscribe to <strong>messages</strong> and <strong>message_echoes</strong>.
                    </li>
                    <li>
                        Messenger → add each branch’s Facebook Page, generate its <strong>Page access token</strong>, and paste the Page ID and token into that branch: Shop → Branches → the branch → <strong>Facebook page</strong> tab → Connect check.
                    </li>
                    <li>
                        Switch on <strong>Receive and send Messenger chats</strong> below and save. Until the app is Live and approved by Meta for pages_messaging, only people with a role on the app can chat with the Pages.
                    </li>
                </ol>
            </Section>
            <Section title="Webhook">
                <SectionNote>Paste these two into Meta (step 3).</SectionNote>
                <Details>
                    <Entry label="Callback URL">
                        <span className="font-mono break-all">{record.callbackUrl}</span> <CopyButton text={record.callbackUrl} what="Callback URL" />
                    </Entry>
                    <Entry label="Verify token" note={token ? 'Shown only now. Copy it before you leave this page.' : 'A new token replaces the old one: paste it into Meta again.'}>
                        {token ? (
                            <>
                                <span className="font-mono break-all">{token}</span> <CopyButton text={token} what="Verify token" />
                            </>
                        ) : (
                            'Hidden. Make a new one if you lost it.'
                        )}
                    </Entry>
                </Details>
            </Section>
            <Section title="Meta app">
                <SectionNote>From App settings → Basic. The App secret is stored encrypted and never shown again.</SectionNote>
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    <TextField label="App ID" hint="Digits only." error={errors.appId} value={appId} onValueChange={setAppId} inputMode="numeric" maxLength={50} autoComplete="off" />
                    <div className="space-y-3">
                        {record.hasSecret && (
                            <>
                                <Details>
                                    <Entry label="App secret">Saved ••••</Entry>
                                </Details>
                                <SwitchField label="Replace app secret" hint="Type a new one to take its place." checked={replace} onChange={setReplace} />
                            </>
                        )}
                        {typing && <SecretField label={record.hasSecret ? 'New app secret' : 'App secret'} hint="From App settings → Basic." error={errors.appSecret} value={appSecret} onChange={setSecret} reveal={false} maxLength={1000} />}
                    </div>
                    <div className="sm:col-span-2">
                        <SwitchField label="Receive and send Messenger chats" hint="Off: webhooks are ignored and replies are not sent." checked={enabled} onChange={setEnabled} />
                    </div>
                </div>
            </Section>
        </SettingsForm>
    );
}

export default function Meta() {
    /** The verify token, only while this visit has it */
    const [token, setToken] = useState<string | null>(null);
    const asked = useRef(false);

    // Shown once: the first time the screen is opened after the token was made
    useEffect(() => {
        if (asked.current) {
            return;
        }

        asked.current = true;
        api<{ verifyToken: string | null }>('POST', '/meta/verify-token/reveal').then(
            ({ verifyToken }) => verifyToken && setToken(verifyToken),
            (failure) => toast.error(errorMessage(failure)),
        );
    }, []);

    const makeNew = async () => {
        if (!(await confirm({ title: 'Make a new verify token', description: 'Meta must be given the new token again, or it can’t re-check the webhook.', label: 'Confirm', icon: 'refresh-cw' }))) {
            return;
        }

        try {
            const { verifyToken } = await api<{ verifyToken: string }>('POST', '/meta/verify-token');
            setToken(verifyToken);
            toast.success('New verify token made', { description: 'Copy it now: it is not shown again.' });
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    return (
        <SettingsPage<Settings>
            title="Meta (Messenger)"
            path="/meta"
            actions={() => (
                <Button icon="refresh-cw" onClick={makeNew}>
                    Make a new verify token
                </Button>
            )}
        >
            {(record, onSaved, version) => <Form key={version} record={record} token={token} onSaved={onSaved} />}
        </SettingsPage>
    );
}
