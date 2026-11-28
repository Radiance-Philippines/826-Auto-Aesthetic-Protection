import { Drawer, DrawerBackdrop, DrawerClose, DrawerContent, DrawerTitle, DrawerTrigger } from '@ui-sdk/react/drawer';
import { useEffect, useRef, useState } from 'react';

import { api, errorMessage } from '../lib/api';
import { apiBase, csrfToken } from '../lib/boot';
import { useLive } from '../lib/live';
import { useMe } from '../lib/me';
import { useResource } from '../lib/useResource';
import { Button, buttonClass } from '../ui/Button';
import { cx } from '../ui/cx';
import { Icon, type IconName } from '../ui/icons';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { toast } from '../ui/toast';
import { ConsoleLink } from './ConsoleLink';

type Note = {
    id: string;
    title: string;
    body: string;
    status: string | null;
    icon: string | null;
    read: boolean;
    ago: string;
    actions: { label: string; url: string; marksRead: boolean; newTab: boolean }[];
};

/** The heroicon a notification was stored with → ours */
const icons: Record<string, IconName> = { 'heroicon-o-bell-alert': 'bell', 'heroicon-o-chat-bubble-left-right': 'message-circle' };

/** The bell: heads-ups and new messages, looked at again every 30 s and when Radiance Flow says so */
export function NotificationBell() {
    const { me } = useMe();
    const [open, setOpen] = useState(false);
    const [page, setPage] = useState(1);
    const { data, error, reload } = useResource<{ data: Note[]; meta: { unread: number; more: boolean } }>(`/notifications?page=${page}`);
    const unread = data?.meta.unread ?? 0;
    const heading = useRef<HTMLHeadingElement>(null);

    useLive(me.live, 'notify', null, reload);

    useEffect(() => {
        const timer = window.setInterval(() => document.visibilityState === 'visible' && reload(), 30_000);

        return () => window.clearInterval(timer);
    }, [reload]);

    const send = async (method: 'POST' | 'DELETE', path: string) => {
        try {
            await api(method, path);
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        reload();
    };

    // Opening the link may load a new page: keepalive lets the request finish anyway
    const markRead = async (id: string) => {
        await fetch(`${apiBase}/notifications/${id}/read`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() } });
        reload();
    };

    /** The button pressed is about to go: keep the keyboard in the panel */
    const sendAndStay = (method: 'POST' | 'DELETE', path: string) => {
        heading.current?.focus();
        void send(method, path);
    };

    return (
        <Drawer open={open} onOpenChange={setOpen} side="right">
            <DrawerTrigger aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'} className={cx(buttonClass('ghost', 'md', true), 'relative')}>
                <Icon name="bell" className="size-5" />
                {unread > 0 && (
                    <span aria-hidden="true" className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[0.625rem] font-bold text-primary-foreground tabular-nums">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </DrawerTrigger>
            <DrawerBackdrop className="bg-black/50" />
            <DrawerContent className="flex h-full w-[min(26rem,100vw)] max-w-full flex-col gap-0 border-0 border-s border-border bg-card p-0 text-foreground">
                <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <DrawerTitle className="m-0 text-base font-semibold">
                        <span ref={heading} tabIndex={-1} className="outline-none">
                            Notifications
                        </span>
                    </DrawerTitle>
                    <DrawerClose aria-label="Close notifications" className={`${buttonClass('ghost', 'sm', true)} shadow-none`}>
                        <Icon name="x" />
                    </DrawerClose>
                </div>

                {!!data?.data.length && (
                    <div className="flex flex-wrap gap-2 border-b border-border px-4 py-2">
                        <Button icon="check" size="sm" disabled={unread === 0} onClick={() => sendAndStay('POST', '/notifications/read-all')}>
                            Mark all as read
                        </Button>
                        <Button icon="trash-2" size="sm" variant="destructive" onClick={() => sendAndStay('DELETE', '/notifications')}>
                            Clear
                        </Button>
                    </div>
                )}

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    {error && (
                        <div className="p-3">
                            <ErrorState message={error} onRetry={reload} />
                        </div>
                    )}
                    {!data ? (
                        !error && <Loading rows={3} className="p-3" />
                    ) : data.data.length === 0 ? (
                        <div className="p-3">
                            <EmptyState icon="bell" title="No notifications">
                                Please check again later.
                            </EmptyState>
                        </div>
                    ) : (
                        <ul className="m-0 list-none p-0">
                            {data.data.map((note) => (
                                <li key={note.id} className={cx('flex gap-3 border-b border-border px-4 py-3', !note.read && 'bg-selected/50')}>
                                    <Icon name={icons[note.icon ?? ''] ?? 'info'} className={cx('mt-0.5 size-5', note.status === 'warning' ? 'text-warning-foreground' : 'text-primary')} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold break-words">
                                            {note.title}
                                            {!note.read && <span className="sr-only"> (unread)</span>}
                                        </p>
                                        {note.body && <p className="text-sm break-words">{note.body}</p>}
                                        <p className="mt-0.5 text-xs text-muted-foreground">{note.ago}</p>
                                        {note.actions.length > 0 && (
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {note.actions.map((action) => (
                                                    <ConsoleLink
                                                        key={action.label}
                                                        href={action.url}
                                                        target={action.newTab ? '_blank' : undefined}
                                                        rel={action.newTab ? 'noopener' : undefined}
                                                        className={buttonClass('outline', 'sm')}
                                                        onClick={() => {
                                                            if (action.marksRead && !note.read) {
                                                                void markRead(note.id);
                                                            }

                                                            setOpen(false);
                                                        }}
                                                    >
                                                        <Icon name="external-link" />
                                                        {action.label}
                                                    </ConsoleLink>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <button type="button" aria-label={`Dismiss ${note.title}`} className={`${buttonClass('ghost', 'sm', true)} shadow-none`} onClick={() => sendAndStay('DELETE', `/notifications/${note.id}`)}>
                                        <Icon name="x" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {data?.meta.more && (
                        <div className="p-3 text-center">
                            <Button icon="chevron-down" size="sm" onClick={() => setPage(page + 1)}>
                                Show older
                            </Button>
                        </div>
                    )}
                </div>
            </DrawerContent>
        </Drawer>
    );
}
