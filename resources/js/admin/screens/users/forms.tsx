import { type FormEvent, useState } from 'react';

import { api } from '../../lib/api';
import { Button } from '../../ui/Button';
import { ModalActions } from '../../ui/Dialog';
import { TextField } from '../../ui/Field';
import { type Option, SelectField } from '../../ui/Select';
import { toast } from '../../ui/toast';
import { Alert, CheckboxListField, useSubmit } from '../bookings/parts';

export type Person = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    branchNames: string;
    branchIds: number[];
    ownList: boolean;
    access: string[];
    sample: boolean;
    twoFactor: boolean;
    passkeys: number;
    invited: string | null;
    lastViewedAs: string;
    isSelf: boolean;
    canViewAs: boolean;
    canDelete: boolean;
};

export type Options = { roles: Option[]; areas: Option[]; defaults: Record<string, string[]>; branches: Option[]; newRole: string; superAdmin: string };

const BRANCH_HINT = 'The bookings, queue and closures they work with.';
const ROLE_HINT = 'A super admin sees every branch and everything. The others work with their own branches only.';

type FormProps = { options: Options; onDone: () => void; onCancel: () => void };

export function InviteForm({ options, onDone, onCancel }: FormProps) {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [role, setRole] = useState(options.newRole);
    const [branches, setBranches] = useState<string[]>([]);
    const { errors, failed, busy, submit } = useSubmit();

    const send = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const sent = await api<{ message: string }>('POST', '/users', { name, email, role, branches: role === options.superAdmin ? [] : branches.map(Number) });
            toast.success(sent.message);
            onDone();
        });
    };

    return (
        <form onSubmit={send} noValidate className="space-y-4">
            {failed && <Alert title="Invite not sent">{failed}</Alert>}
            <TextField label="Name" hint="Their name as the team knows it." error={errors.name} value={name} onValueChange={setName} required maxLength={120} autoComplete="off" />
            <TextField label="Email" type="email" hint="The invite goes here, and they sign in with it." error={errors.email} value={email} onValueChange={setEmail} required maxLength={190} autoComplete="off" />
            <SelectField label="Role" hint={ROLE_HINT} error={errors.role} options={options.roles} value={role} onChange={setRole} />
            {role !== options.superAdmin && <CheckboxListField label="Branches" hint={BRANCH_HINT} error={errors.branches} options={options.branches} value={branches} onChange={setBranches} columns />}
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="send" variant="primary" busy={busy}>
                    Send invite
                </Button>
            </ModalActions>
        </form>
    );
}

export function EditForm({ person, options, onDone, onCancel }: FormProps & { person: Person }) {
    const [name, setName] = useState(person.name);
    const [email, setEmail] = useState(person.email);
    const [role, setRole] = useState(person.role);
    const [access, setAccess] = useState(person.access);
    const [branches, setBranches] = useState(person.branchIds.map(String));
    const { errors, failed, busy, submit } = useSubmit();

    // Changing the role starts the ticks over, from what the role does by default
    const changeRole = (next: string) => {
        setRole(next);
        setAccess(options.defaults[next] ?? []);
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const chosen = role === options.superAdmin ? {} : { access, branches: branches.map(Number) };
            await api('PATCH', `/users/${person.id}`, { name, email, role, ...chosen });
            toast.success('Saved');
            onDone();
        });
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <TextField label="Name" hint="Their name as the team knows it." error={errors.name} value={name} onValueChange={setName} required maxLength={120} autoComplete="off" />
            <TextField label="Email" type="email" hint="They sign in with this." error={errors.email} value={email} onValueChange={setEmail} required maxLength={190} autoComplete="off" />
            {person.isSelf ? (
                <TextField label="Role" hint="You can’t change your own role." error={errors.role} value={person.roleLabel} readOnly disabled />
            ) : (
                <SelectField label="Role" hint={ROLE_HINT} error={errors.role} options={options.roles} value={role} onChange={changeRole} />
            )}
            {role !== options.superAdmin && (
                <>
                    <CheckboxListField
                        label="Extra access"
                        hint="Ticked is what this role does by default. Tick or untick to give this account its own list; change the role to start over."
                        error={errors.access}
                        options={options.areas}
                        value={access}
                        onChange={setAccess}
                        columns
                    />
                    <CheckboxListField label="Branches" hint={BRANCH_HINT} error={errors.branches} options={options.branches} value={branches} onChange={setBranches} columns />
                </>
            )}
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save changes
                </Button>
            </ModalActions>
        </form>
    );
}
