import { type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';

import { api, errorMessage, fieldErrors } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button } from '../../ui/Button';
import { cx } from '../../ui/cx';
import { Modal, ModalActions } from '../../ui/Dialog';
import { Icon } from '../../ui/icons';
import { type Option, SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { TextAreaField } from '../../ui/TextArea';
import { toast } from '../../ui/toast';
import { Alert, Tone, useSubmit } from '../bookings/parts';
import { SearchField } from '../bookings/SearchField';

type Message = {
    id: number;
    right: boolean;
    body: string | null;
    attachments: { type?: string; url?: string | null; name?: string | null }[];
    by: string | null;
    at: string;
    failed: boolean;
    note: string | null;
};

type Chat = {
    id: number;
    name: string;
    channel: 'messenger' | 'headsup' | 'internal';
    branch: string | null;
    sample: boolean;
    done: boolean;
    booking: { reference: string; id: number | null } | null;
    customer: { id: number | null; guestId: number; name: string; label: string } | null;
    assignee: { id: number; name: string } | null;
    pastWindow: boolean;
    maxLength: number;
    messages: Message[];
    hasOlder: boolean;
};

const link = 'underline underline-offset-4';

function Attachment({ file }: { file: Message['attachments'][number] }) {
    const safe = file.url?.startsWith('https://') ? file.url : null;
    const label = file.name ?? (file.type ? file.type.charAt(0).toUpperCase() + file.type.slice(1) : 'Attachment');

    if (safe && file.type === 'image') {
        return (
            <a href={safe} target="_blank" rel="noopener noreferrer" className="mb-1 block">
                <img src={safe} alt="Photo from the customer" loading="lazy" referrerPolicy="no-referrer" className="max-h-60 max-w-full rounded-lg" />
            </a>
        );
    }

    return safe ? (
        <a href={safe} target="_blank" rel="noopener noreferrer" className={cx('mb-1 block break-all', link)}>
            {label}
        </a>
    ) : (
        <span className="mb-1 block italic">{label}</span>
    );
}

function AssignForm({ chat, onClose, onDone }: { chat: Chat; onClose: () => void; onDone: () => void }) {
    const { data, error, reload } = useResource<{ data: Option[] }>(`/inbox/${chat.id}/team`);
    const [person, setPerson] = useState(chat.assignee ? String(chat.assignee.id) : 'none');
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            await api('POST', `/inbox/${chat.id}/assign`, { assignedTo: person === 'none' ? null : Number(person) });
            onClose();
            onDone();
        });
    };

    if (!data) {
        return error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={2} />;
    }

    return (
        <form onSubmit={save} noValidate className="space-y-4">
            {failed && <Alert>{failed}</Alert>}
            <SelectField label="Team member" hint="Someone from this branch who uses the inbox." error={errors.assignedTo} options={[{ value: 'none', label: 'Nobody' }, ...data.data]} value={person} onChange={setPerson} />
            <ModalActions onCancel={onClose}>
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save
                </Button>
            </ModalActions>
        </form>
    );
}

function CustomerForm({ chat, onClose, onDone }: { chat: Chat; onClose: () => void; onDone: () => void }) {
    const [guest, setGuest] = useState<Option | null>(chat.customer ? { value: String(chat.customer.guestId), label: chat.customer.label } : null);
    const { errors, failed, busy, submit } = useSubmit();

    const send = (guestId: number | null) =>
        submit(async () => {
            await api('POST', `/inbox/${chat.id}/customer`, { guestId });
            onClose();
            onDone();
        });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                void send(guest ? Number(guest.value) : null);
            }}
            noValidate
            className="space-y-4"
        >
            {failed && <Alert>{failed}</Alert>}
            <SearchField label="Customer" hint="Search by name or phone number." error={errors.guestId} path={`/inbox/${chat.id}/customers`} value={guest} onChange={setGuest} placeholder="No customer" />
            {chat.customer && (
                <Button icon="x" size="sm" onClick={() => void send(null)}>
                    Remove the customer
                </Button>
            )}
            <ModalActions onCancel={onClose}>
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save
                </Button>
            </ModalActions>
        </form>
    );
}

function Composer({ chat, onSent }: { chat: Chat; onSent: () => void }) {
    const [reply, setReply] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const team = chat.channel !== 'messenger';

    const send = async () => {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const sent = await api<{ savedOnly: boolean }>('POST', `/inbox/${chat.id}/reply`, { body: reply });

            if (sent.savedOnly && !team) {
                toast.success('Saved here only. This is a demo: nothing was sent to Messenger.');
            }

            setReply('');
        } catch (failure) {
            setError(fieldErrors(failure).body ?? errorMessage(failure));
        }

        setBusy(false);
        // A refused send is kept in the thread with the reason
        onSent();
    };

    const keys = (event: KeyboardEvent) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            void send();
        }
    };

    return (
        <footer className="space-y-2 border-t border-border p-3">
            {team && (
                <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                    <Icon name="shield-check" className="mt-0.5 size-4" />
                    {chat.channel === 'internal' ? `Team thread. Only ${chat.branch} staff see it; it is never sent to a customer or to Messenger.` : 'Internal note to operations. It stays in the system and is never sent to the customer.'}
                </p>
            )}
            {chat.pastWindow && <p className="text-xs text-warning-foreground">It has been over 24 hours since the customer’s last message. Meta may refuse this reply; if it does, answer in Meta Business Suite.</p>}
            {error && <Alert title="Message not sent">{error}</Alert>}
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    void send();
                }}
                noValidate
                className="space-y-2"
            >
                <TextAreaField
                    label="Reply"
                    hideLabel
                    hint="Ctrl+Enter sends."
                    value={reply}
                    onValueChange={setReply}
                    onKeyDown={keys}
                    rows={2}
                    maxLength={chat.maxLength}
                    placeholder={chat.channel === 'internal' ? 'Reply to the team…' : chat.channel === 'headsup' ? 'Internal note to operations…' : 'Reply on Messenger…'}
                />
                <div className="flex justify-end">
                    <Button type="submit" icon="arrow-right-to-line" variant="primary" busy={busy}>
                        Send
                    </Button>
                </div>
            </form>
        </footer>
    );
}

export function Thread({ id, tick, onBack, onChanged }: { id: number; tick: number; onBack: () => void; onChanged: () => void }) {
    const [limit, setLimit] = useState(50);
    const { data: chat, error, reload } = useResource<Chat>(`/inbox/${id}?limit=${limit}`);
    const [dialog, setDialog] = useState<'assign' | 'customer' | null>(null);
    const [busy, setBusy] = useState(false);
    const messages = useRef<HTMLDivElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const latest = chat?.messages.at(-1)?.id;
    const opened = chat?.id;

    // The page's own look-again
    useEffect(() => {
        if (tick > 0) {
            reload();
        }
    }, [tick, reload]);

    // Opening it marked it read: the list and the sidebar's count catch up, and the keyboard starts here
    useEffect(() => {
        if (opened) {
            onChanged();
            heading.current?.focus({ preventScroll: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [opened]);

    // The newest message in view; older ones loaded above leave the place alone
    useEffect(() => {
        messages.current?.scrollTo({ top: messages.current.scrollHeight });
    }, [latest]);

    const panel = 'flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card lg:max-h-[calc(100dvh-10rem)]';

    if (!chat) {
        return (
            <section aria-label="Chat" className={cx(panel, 'gap-3 p-3')}>
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={4} />}
                <Button icon="arrow-left" onClick={onBack}>
                    Back to chats
                </Button>
            </section>
        );
    }

    const done = async () => {
        setBusy(true);

        try {
            await api('POST', `/inbox/${chat.id}/done`, { done: !chat.done });
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        setBusy(false);
        reload();
        onChanged();
    };

    const after = () => {
        reload();
        onChanged();
    };

    const bookingLink =
        chat.booking &&
        (chat.booking.id ? (
            <Link to={`/bookings/${chat.booking.id}`} className={link}>
                Booking {chat.booking.reference}
            </Link>
        ) : (
            <span>Booking {chat.booking.reference}</span>
        ));

    return (
        <section aria-label={`Chat with ${chat.name}`} className={panel}>
            <header className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                <Button icon="arrow-left" aria-label="Back to chats" variant="ghost" className="lg:hidden" onClick={onBack} />
                <div className="min-w-0 flex-1">
                    <h2 ref={heading} tabIndex={-1} className="m-0 flex flex-wrap items-center gap-2 text-sm font-semibold break-words">
                        {chat.name}
                        {chat.sample && <Tone tone="warning">Sample</Tone>}
                    </h2>
                    <p className="text-xs break-words text-muted-foreground">
                        {chat.channel === 'messenger' ? (
                            `Messenger · ${chat.branch}`
                        ) : (
                            <>
                                {chat.channel === 'headsup' ? 'Heads-up' : 'Internal'} · {bookingLink}
                                {bookingLink ? ' · ' : ''}
                                {chat.branch}
                                {chat.channel === 'internal' ? ' team' : ''}
                            </>
                        )}
                        {chat.customer && (
                            <>
                                {' · '}
                                {chat.customer.id ? (
                                    <Link to={`/customers/${chat.customer.id}`} className={link}>
                                        Customer: {chat.customer.name}
                                    </Link>
                                ) : (
                                    `Customer: ${chat.customer.name}`
                                )}
                            </>
                        )}
                    </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    <Button icon="user" size="sm" onClick={() => setDialog('assign')}>
                        {chat.assignee ? `Assigned: ${chat.assignee.name}` : 'Assign'}
                    </Button>
                    {chat.channel === 'messenger' && (
                        <Button icon="user-pen" size="sm" onClick={() => setDialog('customer')}>
                            {chat.customer ? 'Change customer' : 'Link customer'}
                        </Button>
                    )}
                    <Button icon={chat.done ? 'undo-2' : 'check'} size="sm" busy={busy} onClick={done}>
                        {chat.done ? 'Reopen' : 'Mark done'}
                    </Button>
                </div>
            </header>

            <div ref={messages} tabIndex={0} role="log" aria-label="Messages" className="flex min-h-48 flex-1 flex-col gap-2 overflow-y-auto p-4">
                {chat.hasOlder && (
                    <Button icon="arrow-up" size="sm" variant="ghost" className="self-center" onClick={() => setLimit((current) => current + 50)}>
                        Load older messages
                    </Button>
                )}
                {chat.messages.map((message) => (
                    <div key={message.id} className={cx('flex max-w-[85%] flex-col gap-1', message.right ? 'items-end self-end' : 'items-start self-start')}>
                        <div
                            className={cx(
                                'max-w-full rounded-2xl px-3 py-2 text-sm break-words',
                                message.failed ? 'rounded-br-sm border border-destructive text-destructive' : message.right ? 'rounded-br-sm bg-primary text-primary-foreground' : 'rounded-bl-sm bg-muted',
                            )}
                        >
                            {message.attachments.map((file, index) => (
                                <Attachment key={index} file={file} />
                            ))}
                            {message.body && <p className="whitespace-pre-line">{message.body}</p>}
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {message.by ? `${message.by} · ` : ''}
                            {message.at}
                            {message.note && <span className={cx(message.failed && 'text-destructive')}> · {message.note}</span>}
                        </span>
                    </div>
                ))}
            </div>

            <Composer chat={chat} onSent={after} />

            {dialog === 'assign' && (
                <Modal open onClose={() => setDialog(null)} title="Assign this chat">
                    <AssignForm chat={chat} onClose={() => setDialog(null)} onDone={after} />
                </Modal>
            )}
            {dialog === 'customer' && (
                <Modal open onClose={() => setDialog(null)} title="Link this chat to a customer">
                    <CustomerForm chat={chat} onClose={() => setDialog(null)} onDone={after} />
                </Modal>
            )}
        </section>
    );
}
