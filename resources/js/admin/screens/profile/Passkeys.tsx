import { type FormEvent, useEffect, useState } from 'react';

import { createPasskey, explainPasskeyError, passkeysSupported, signalPasskeys } from '../../auth/passkey';
import { api, ApiError, errorMessage } from '../../lib/api';
import { Button } from '../../ui/Button';
import { confirm, Modal, ModalActions } from '../../ui/Dialog';
import { TextField } from '../../ui/Field';
import { toast } from '../../ui/toast';
import { Alert, Section, useSubmit } from '../bookings/parts';
import type { Profile } from './types';

type Item = Profile['passkeys']['items'][number];

export function Passkeys({ profile, onSaved }: { profile: Profile; onSaved: (next: Profile) => void }) {
    const { allowed, items, rpId, userId, ids } = profile.passkeys;
    const [name, setName] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [renaming, setRenaming] = useState<Item | null>(null);

    // The password manager forgets what was removed here
    useEffect(() => {
        void signalPasskeys(rpId, userId, ids);
    }, [rpId, userId, ids.join()]);

    const add = async (event: FormEvent) => {
        event.preventDefault();
        setMessage(null);
        setBusy(true);

        try {
            const options = await api<PublicKeyCredentialCreationOptionsJSON>('POST', '/profile/passkeys/options');
            const next = await api<Profile>('POST', '/profile/passkeys', { credential: await createPasskey(options), name });
            setName('');
            onSaved(next);
            toast.success('Passkey added');
        } catch (error) {
            setMessage(error instanceof ApiError ? error.message : explainPasskeyError(error));
        }

        setBusy(false);
    };

    const remove = async (item: Item) => {
        if (!(await confirm({ title: 'Remove this passkey?', description: `“${item.name}” can no longer sign you in. Remove it from your device or password manager too.`, label: 'Remove', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            onSaved(await api<Profile>('DELETE', `/profile/passkeys/${item.id}`));
            toast.success('Passkey removed');
        } catch (error) {
            toast.error(errorMessage(error));
        }
    };

    return (
        <Section title="Passkeys">
            <p className="mb-3 text-sm text-muted-foreground">Sign in with your fingerprint, face, PIN or a security key instead of a password. A passkey is a strong sign-in on its own, so it skips the 2FA code.</p>
            {!allowed && (
                <div className="mb-3">
                    <Alert tone="warning">Passkey sign-in is turned off by a super admin right now. Your passkeys are kept and work again when it is turned back on.</Alert>
                </div>
            )}
            {items.length === 0 ? (
                <p className="mb-3 text-sm text-muted-foreground">No passkeys yet. Add one for each device you sign in on.</p>
            ) : (
                <ul aria-label="Your passkeys" className="m-0 mb-3 list-none divide-y divide-border rounded-lg border border-border p-0">
                    {items.map((item) => (
                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                            <div className="min-w-0">
                                <p className="text-sm font-medium break-words">{item.name}</p>
                                <p className="text-xs text-muted-foreground">
                                    Added {item.added} · {item.lastUsed ? `Last used ${item.lastUsed}` : 'Not used to sign in yet'}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Button icon="pencil" size="sm" onClick={() => setRenaming(item)} aria-label={`Rename ${item.name}`}>
                                    Rename
                                </Button>
                                <Button icon="trash-2" size="sm" variant="destructive" onClick={() => void remove(item)} aria-label={`Remove ${item.name}`}>
                                    Remove
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {allowed &&
                (passkeysSupported() ? (
                    <form onSubmit={add} className="space-y-3">
                        <TextField label="Name for this device" hint="Optional. For example: Work laptop." value={name} onChange={(event) => setName(event.target.value)} maxLength={60} autoComplete="off" />
                        {message && <Alert>{message}</Alert>}
                        <Button type="submit" icon="plus" busy={busy}>
                            Add a passkey
                        </Button>
                    </form>
                ) : (
                    <p className="text-sm text-muted-foreground">This browser can’t make passkeys here (they need https or localhost, and a recent browser).</p>
                ))}
            <Modal open={renaming !== null} onClose={() => setRenaming(null)} title="Rename passkey">
                {renaming && (
                    <Rename
                        item={renaming}
                        onCancel={() => setRenaming(null)}
                        onSaved={(next) => {
                            setRenaming(null);
                            onSaved(next);
                            toast.success('Passkey renamed');
                        }}
                    />
                )}
            </Modal>
        </Section>
    );
}

function Rename({ item, onSaved, onCancel }: { item: Item; onSaved: (next: Profile) => void; onCancel: () => void }) {
    const [name, setName] = useState(item.name);
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => onSaved(await api<Profile>('PATCH', `/profile/passkeys/${item.id}`, { name })));
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            <TextField label="Name" hint="So you know which device it is." error={errors.name} value={name} onChange={(event) => setName(event.target.value)} maxLength={60} required />
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save name
                </Button>
            </ModalActions>
        </form>
    );
}
