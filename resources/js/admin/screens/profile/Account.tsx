import { type FormEvent, useState } from 'react';

import { api } from '../../lib/api';
import { useMe } from '../../lib/me';
import { SecretField } from '../../shell/SecretField';
import { Button } from '../../ui/Button';
import { TextField } from '../../ui/Field';
import { toast } from '../../ui/toast';
import { Alert, Section, useSubmit } from '../bookings/parts';
import type { Profile } from './types';

/** Name, email and password. A new password or email asks for the current one. */
export function Account({ profile, onSaved }: { profile: Profile; onSaved: (next: Profile) => void }) {
    const [name, setName] = useState(profile.name);
    const [email, setEmail] = useState(profile.email);
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [current, setCurrent] = useState('');
    const { errors, failed, busy, submit } = useSubmit();
    const { reload } = useMe();
    const sensitive = password !== '' || email !== profile.email;

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const next = await api<Profile>('PATCH', '/profile', { name, email, password, password_confirmation: confirmation, current_password: current });
            setPassword('');
            setConfirmation('');
            setCurrent('');
            onSaved(next);
            reload();
            toast.success('Profile saved');
        });
    };

    return (
        <Section title="Account">
            <form onSubmit={save} noValidate className="space-y-4">
                {failed && <Alert title="Not saved">{failed}</Alert>}
                <TextField label="Name" hint="As the team sees it." error={errors.name} value={name} onChange={(event) => setName(event.target.value)} autoComplete="name" maxLength={255} required />
                <TextField label="Email" hint="You sign in with it." error={errors.email} type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" spellCheck={false} maxLength={255} required />
                <SecretField label="New password" hint="At least 8 characters. Leave blank to keep the one you have." error={errors.password} value={password} onChange={setPassword} />
                {password !== '' && <SecretField label="Confirm new password" hint="The same password again." error={errors.password_confirmation} value={confirmation} onChange={setConfirmation} />}
                {sensitive && <SecretField label="Current password" hint="Needed to change your email or password." error={errors.current_password} value={current} onChange={setCurrent} autoComplete="current-password" />}
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save profile
                </Button>
            </form>
        </Section>
    );
}
