import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@ui-sdk/react/collapsible';
import { useState } from 'react';

import { api } from '../lib/api';
import { SecretField } from '../shell/SecretField';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { Button, buttonClass } from '../ui/Button';
import { cx } from '../ui/cx';
import { TextField } from '../ui/Field';
import { Icon } from '../ui/icons';
import { toast } from '../ui/toast';
import { Alert, ChoiceField, Section, useSubmit } from './bookings/parts';

type Result = { ok: boolean; title: string; body: string };
type Settings = { address: string | null; fromName: string | null; notifyEmail: string | null; smtpHost: string | null; smtpPort: number | null; configured: boolean; test: Result | null };

function Form({ record, onSaved }: { record: Settings; onSaved: (record: Settings) => void }) {
    const [values, setValues] = useState({
        address: record.address ?? '',
        fromName: record.fromName ?? '',
        password: '',
        notifyEmail: record.notifyEmail ?? '',
        smtpHost: record.smtpHost ?? '',
        smtpPort: record.smtpPort ?? 465,
    });
    const [server, setServer] = useState(false);
    const { errors, failed, busy, submit } = useSubmit();
    const set = <Key extends keyof typeof values>(key: Key) => (value: (typeof values)[Key]) => setValues((current) => ({ ...current, [key]: value }));
    const text = (name: 'address' | 'fromName' | 'notifyEmail' | 'smtpHost') => ({ value: values[name], error: errors[name], onValueChange: set(name) });

    const send = (path: string) =>
        submit(async () => {
            const saved = await api<Settings>('POST', path, values);

            if (!saved.test) {
                toast.success('Email settings saved');
            }

            onSaved(saved);
        });

    return (
        <>
            {record.test &&
                (record.test.ok ? (
                    <div role="status" className="flex max-w-3xl items-start gap-2 rounded-xl border border-primary bg-selected px-4 py-3 text-sm text-selected-foreground">
                        <Icon name="circle-check" className="mt-0.5 size-4" />
                        <div className="min-w-0">
                            <p className="font-semibold">{record.test.title}</p>
                            <p className="break-words">{record.test.body}</p>
                        </div>
                    </div>
                ) : (
                    <div className="max-w-3xl">
                        <Alert title={record.test.title}>{record.test.body}</Alert>
                    </div>
                ))}
            <SettingsForm
                onSubmit={() => send('/email-settings')}
                errors={errors}
                failed={failed}
                busy={busy}
                extra={
                    <Button type="button" icon="send" onClick={() => send('/email-settings/test')}>
                        Save and send a test
                    </Button>
                }
            >
                <Section title="Mailbox">
                    <SectionNote>The email account the site sends from. New booking requests are emailed to it, or to the address below.</SectionNote>
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <TextField label="Email address" type="email" hint="The mailbox’s own address." required maxLength={190} autoComplete="off" {...text('address')} />
                        <TextField label="Sender name" hint="What people see as the sender." placeholder="826 Auto Aesthetic & Protection" maxLength={80} autoComplete="off" {...text('fromName')} />
                        <SecretField
                            className="sm:col-span-2"
                            label="Mailbox password"
                            hint={record.configured ? 'Saved (hidden). Leave blank to keep it, or type a new one if you changed it.' : 'The password of the email account (Hostinger: hPanel → Emails). Stored encrypted.'}
                            error={errors.password}
                            value={values.password}
                            onChange={set('password')}
                            required={!record.configured}
                            maxLength={190}
                        />
                        <TextField className="sm:col-span-2" label="Send booking alerts to" type="email" hint="Optional, e.g. a Gmail the team checks. Leave blank to send them to the mailbox above." maxLength={190} autoComplete="off" {...text('notifyEmail')} />
                    </div>
                </Section>

                <Collapsible open={server || !!errors.smtpHost || !!errors.smtpPort} onOpenChange={setServer} className="rounded-xl border border-border bg-card p-4">
                    <CollapsibleTrigger className={cx(buttonClass('ghost', 'sm'), '-ms-2')}>
                        <Icon name={server ? 'chevron-down' : 'chevron-right'} />
                        Server
                    </CollapsibleTrigger>
                    <p className="mt-1 text-sm text-muted-foreground">Hostinger’s, already filled in. Only change these if the mailbox moves to another provider.</p>
                    <CollapsiblePanel>
                        <div className="grid items-start gap-4 pt-4 sm:grid-cols-2">
                            <TextField label="Outgoing (SMTP) server" hint="Like smtp.hostinger.com." required maxLength={190} autoComplete="off" {...text('smtpHost')} />
                            <ChoiceField
                                label="SMTP port"
                                hint="465 unless your provider says 587."
                                error={errors.smtpPort}
                                options={[
                                    { value: '465', label: '465 (SSL)', icon: 'shield-check' },
                                    { value: '587', label: '587 (STARTTLS)', icon: 'shield-check' },
                                ]}
                                value={String(values.smtpPort)}
                                onChange={(port) => set('smtpPort')(Number(port))}
                            />
                        </div>
                    </CollapsiblePanel>
                </Collapsible>
            </SettingsForm>
        </>
    );
}

export default function EmailSettings() {
    return (
        <SettingsPage<Settings> title="Email settings" path="/email-settings">
            {(record, onSaved, version) => <Form key={version} record={record} onSaved={onSaved} />}
        </SettingsPage>
    );
}
