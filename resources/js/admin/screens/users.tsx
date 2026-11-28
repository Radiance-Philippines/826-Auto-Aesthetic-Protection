import { useState } from 'react';

import { api, errorMessage } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { type Column, DataTable, useClientTable } from '../ui/DataTable';
import { confirm, Modal } from '../ui/Dialog';
import { SwitchField } from '../ui/Field';
import { type IconName } from '../ui/icons';
import { ActionMenu } from '../ui/Menu';
import { PageHeader } from '../ui/PageHeader';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { toast } from '../ui/toast';
import { ColumnsMenu, Tone } from './bookings/parts';
import { EditForm, InviteForm, type Options, type Person } from './users/forms';

const allColumns: Column<Person>[] = [
    {
        id: 'name',
        header: 'Name',
        primary: true,
        sortValue: (person) => person.name,
        cell: (person) => (
            <>
                <span className="block break-words">{person.name}</span>
                <span className="block text-xs font-normal break-all text-muted-foreground">{person.email}</span>
            </>
        ),
    },
    {
        id: 'role',
        header: 'Role',
        cell: (person) => (
            <>
                <Tone tone={person.role === 'super_admin' ? 'primary' : 'gray'}>{person.roleLabel}</Tone>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                    {person.branchNames}
                    {person.ownList && ' · own access list'}
                </span>
            </>
        ),
    },
    { id: 'sample', header: 'Sample', cell: (person) => <Tone tone={person.sample ? 'warning' : 'gray'}>{person.sample ? 'Sample' : 'No'}</Tone> },
    { id: 'twoFactor', header: '2FA', cell: (person) => <Tone tone={person.twoFactor ? 'success' : 'gray'}>{person.twoFactor ? 'On' : 'Off'}</Tone> },
    { id: 'passkeys', header: 'Passkeys', align: 'center', cell: (person) => <Tone tone={person.passkeys > 0 ? 'success' : 'gray'}>{person.passkeys}</Tone> },
    {
        id: 'status',
        header: 'Status',
        cell: (person) => (
            <>
                <Tone tone={person.invited ? 'warning' : 'success'}>{person.invited ? 'Invite sent' : 'Active'}</Tone>
                {person.invited && <span className="mt-0.5 block text-xs text-muted-foreground">{person.invited}</span>}
            </>
        ),
    },
    { id: 'viewed', header: 'Last viewed as', cell: (person) => person.lastViewedAs },
];

export default function Users() {
    const { data, error, reload } = useResource<{ data: Person[]; options: Options }>('/users');
    const [inviting, setInviting] = useState(false);
    const [editing, setEditing] = useState<Person | null>(null);
    const [sampleOnly, setSampleOnly] = useState(false);
    const [shown, setShown] = useState<Record<string, boolean>>({ viewed: true });

    const people = (data?.data ?? []).filter((person) => !sampleOnly || person.sample);
    const columns = allColumns.filter((column) => column.id !== 'viewed' || shown.viewed);
    const table = useClientTable(people, columns, { searchText: (person) => person.name });

    const act = async (send: () => Promise<{ message?: string } | undefined>, done?: string, failed?: string) => {
        try {
            const answer = await send();
            const message = answer?.message ?? done;

            if (message) {
                toast.success(message);
            }
        } catch (failure) {
            toast.error(failed ? `${failed}: ${errorMessage(failure)}` : errorMessage(failure));
        }

        reload();
    };

    const ask = async (title: string, description: string, icon: IconName, send: () => Promise<{ message?: string }>, failed?: string) => {
        if (await confirm({ title, description, label: 'Confirm', icon })) {
            await act(send, undefined, failed);
        }
    };

    const viewAs = async (person: Person) => {
        try {
            const { url } = await api<{ url: string }>('POST', `/users/${person.id}/view-as`);
            // A new page: the session changed, and with it the CSRF token
            window.location.assign(url);
        } catch (failure) {
            toast.error(`Cannot view as this person: ${errorMessage(failure)}`);
        }
    };

    const remove = async (person: Person) => {
        if (await confirm({ title: `Delete ${person.name}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true })) {
            await act(() => api('DELETE', `/users/${person.id}`), 'Deleted');
        }
    };

    const post = (person: Person, step: string) => () => api<{ message: string }>('POST', `/users/${person.id}/${step}`);

    const invite = (
        <Button icon="user-plus" variant="primary" onClick={() => setInviting(true)}>
            Invite team member
        </Button>
    );

    return (
        <>
            <PageHeader title="Users" description="Who can sign in to the admin, and what each person works with." actions={invite} />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={5} />
            ) : (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <SwitchField label="Sample accounts only" hint="The made-up team accounts used for demos." checked={sampleOnly} onChange={setSampleOnly} />
                        <ColumnsMenu columns={[{ id: 'viewed', label: 'Last viewed as' }]} shown={shown} onChange={setShown} />
                    </div>
                    <DataTable
                        {...table}
                        caption="Users"
                        columns={columns}
                        rowId={(person) => String(person.id)}
                        rowName={(person) => person.name}
                        searchHint="By name."
                        actions={(person) => (
                            <>
                                <Button icon="pencil" size="sm" aria-label={`Edit ${person.name}`} onClick={() => setEditing(person)}>
                                    Edit
                                </Button>
                                <ActionMenu
                                    label={`More actions for ${person.name}`}
                                    actions={[
                                        ...(person.canViewAs ? [{ id: 'view', label: 'View as this user', icon: 'eye' as const, onSelect: () => viewAs(person) }] : []),
                                        ...(person.invited
                                            ? [{ id: 'resend', label: 'Resend invite', icon: 'send' as const, onSelect: () => ask('Resend invite', 'Emails a new link. The old one stops working.', 'send', post(person, 'resend-invite'), 'Invite not sent') }]
                                            : []),
                                        ...(person.twoFactor
                                            ? [
                                                  {
                                                      id: 'two-factor',
                                                      label: 'Reset 2FA',
                                                      icon: 'lock-open' as const,
                                                      onSelect: () => ask('Reset 2FA', 'Clears their authenticator app, recovery codes and email code. They sign in with just their password until they set it up again.', 'lock-open', post(person, 'reset-two-factor')),
                                                  },
                                              ]
                                            : []),
                                        ...(person.passkeys > 0
                                            ? [
                                                  {
                                                      id: 'passkeys',
                                                      label: 'Remove passkeys',
                                                      icon: 'fingerprint' as const,
                                                      onSelect: () =>
                                                          ask('Remove passkeys', 'Removes every passkey of this person, for a lost or stolen device. They sign in with their password (and 2FA) until they add a passkey again.', 'fingerprint', post(person, 'remove-passkeys')),
                                                  },
                                              ]
                                            : []),
                                        { id: 'delete', label: 'Delete', icon: 'trash-2', destructive: true, disabled: !person.canDelete, onSelect: () => remove(person) },
                                    ]}
                                />
                            </>
                        )}
                        empty={
                            <EmptyState icon="users" title={sampleOnly ? 'No sample accounts' : 'No users yet'} action={invite}>
                                {sampleOnly ? 'Switch the filter off to see everyone.' : 'Invite your team by email.'}
                            </EmptyState>
                        }
                    />
                </>
            )}

            <Modal open={inviting} onClose={() => setInviting(false)} title="Invite team member" description="They get an email with a link to choose their password. Everyone but a super admin sees only the branches you pick.">
                {inviting && data && (
                    <InviteForm
                        options={data.options}
                        onCancel={() => setInviting(false)}
                        onDone={() => {
                            setInviting(false);
                            reload();
                        }}
                    />
                )}
            </Modal>

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing ? `Edit ${editing.name}` : 'Edit'}>
                {editing && data && (
                    <EditForm
                        key={editing.id}
                        person={editing}
                        options={data.options}
                        onCancel={() => setEditing(null)}
                        onDone={() => {
                            setEditing(null);
                            reload();
                        }}
                    />
                )}
            </Modal>
        </>
    );
}
