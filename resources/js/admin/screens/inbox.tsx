import { Input } from '@ui-sdk/react/input';
import { useEffect, useId, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { useLive } from '../lib/live';
import { useMe } from '../lib/me';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { cx } from '../ui/cx';
import { controlClass } from '../ui/Field';
import { Icon } from '../ui/icons';
import { PageHeader } from '../ui/PageHeader';
import type { Option } from '../ui/Select';
import { ErrorState, Loading } from '../ui/states';
import { TabBar } from '../ui/Tabs';
import { Alert, ChoiceField, useDebounced } from './bookings/parts';
import { NewThread } from './inbox/NewThread';
import { Thread } from './inbox/Thread';

type Row = {
    id: number;
    name: string;
    avatar: string | null;
    channel: string;
    branch: string | null;
    about: string | null;
    preview: string | null;
    when: string | null;
    unread: number;
    sample: boolean;
    done: boolean;
};

type List = {
    branch: string;
    filter: string;
    channel: string;
    tabs: { key: string; label: string; unread: number }[];
    branches: Option[];
    notice: string | null;
    conversations: Row[];
};

const filters = [
    { value: 'open', label: 'Open', icon: 'inbox' as const },
    { value: 'unread', label: 'Unread', icon: 'circle' as const },
    { value: 'done', label: 'Done', icon: 'check' as const },
    { value: 'all', label: 'All', icon: 'layout-grid' as const },
];

const channels = [
    { value: 'all', label: 'All', icon: 'layout-grid' as const },
    { value: 'messenger', label: 'Messenger', icon: 'message-circle' as const },
    { value: 'headsup', label: 'Heads-up', icon: 'triangle-alert' as const },
    { value: 'internal', label: 'Internal', icon: 'users' as const },
];

function nothing(search: string, filter: string, channel: string): string {
    if (search || filter !== 'open') {
        return 'No chats match.';
    }

    if (channel === 'headsup') {
        return 'No open heads-ups. They show up here when operations changes a booking’s ready date or unit.';
    }

    return channel === 'internal' ? 'No open team threads. Start one with New team thread.' : 'No open chats. They show up here as soon as a customer messages a branch’s Facebook Page, or operations changes a booking.';
}

const chip = 'rounded bg-muted px-1.5 py-0.5 text-[0.6875rem] font-medium text-foreground';

function Avatar({ row }: { row: Row }) {
    const [broken, setBroken] = useState(false);
    const shape = 'flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold';

    if (row.channel === 'internal') {
        return (
            <span aria-hidden="true" className={shape}>
                <Icon name="users" className="size-5" />
            </span>
        );
    }

    return row.avatar && !broken ? (
        <img src={row.avatar} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setBroken(true)} className="size-10 shrink-0 rounded-full object-cover" />
    ) : (
        <span aria-hidden="true" className={shape}>
            {row.name.charAt(0).toUpperCase()}
        </span>
    );
}

export default function Inbox() {
    const { me, reload: reloadMe } = useMe();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState('');
    const settled = useDebounced(search, 400);
    const [starting, setStarting] = useState(false);
    // Bumped when the open thread should look again
    const [tick, setTick] = useState(0);
    const searchId = useId();

    const asked = new URLSearchParams();

    for (const key of ['branch', 'filter', 'channel']) {
        if (params.get(key)) {
            asked.set(key, params.get(key)!);
        }
    }

    if (settled) {
        asked.set('search', settled);
    }

    const { data, error, reload } = useResource<List>(`/inbox${asked.size ? `?${asked}` : ''}`);
    const open = Number(params.get('conversation')) || null;

    const go = (changes: Record<string, string | null>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);

                for (const [key, value] of Object.entries(changes)) {
                    if (value === null) {
                        next.delete(key);
                    } else {
                        next.set(key, value);
                    }
                }

                return next;
            },
            { replace: true },
        );

    const refresh = () => {
        reload();
        setTick((current) => current + 1);
    };

    /** The thread was read or changed: the list and the sidebar's count follow */
    const changed = () => {
        reload();
        reloadMe();
    };

    useLive(me.live, 'inbox', data && /^\d+$/.test(data.branch) ? Number(data.branch) : null, refresh);

    // As the Filament page: look again every 20 seconds while it is on screen
    useEffect(() => {
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                reload();
                setTick((current) => current + 1);
            }
        }, 20_000);

        return () => window.clearInterval(timer);
    }, [reload]);

    const header = (
        <PageHeader
            title="Inbox"
            actions={
                <Button icon="pencil" variant="primary" disabled={!data?.branches.length} onClick={() => setStarting(true)}>
                    New team thread
                </Button>
            }
        />
    );

    if (!data) {
        return (
            <>
                {header}
                {error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={5} />}
            </>
        );
    }

    const body = (
        <div className="grid min-h-[32rem] items-stretch gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]">
            <section aria-label="Chats" className={cx('flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card lg:max-h-[calc(100dvh-10rem)]', open !== null && 'hidden lg:flex')}>
                <div className="space-y-3 border-b border-border p-3">
                    <div>
                        <label htmlFor={searchId} className="sr-only">
                            Search chats
                        </label>
                        <Input id={searchId} type="search" value={search} onChange={(event) => setSearch(event.currentTarget.value)} placeholder="Search by name or message" aria-describedby={`${searchId}-hint`} className={controlClass} />
                        <p id={`${searchId}-hint`} className="mt-1 text-xs text-muted-foreground">
                            By name or the last message.
                        </p>
                    </div>
                    <ChoiceField label="Channel" hint="Customers on Messenger, or team notes." options={channels} value={data.channel} onChange={(channel) => go({ channel: channel === 'all' ? null : channel })} />
                    <ChoiceField label="Show" hint="Open is the to-do list." options={filters} value={data.filter} onChange={(filter) => go({ filter: filter === 'open' ? null : filter })} />
                </div>

                {data.conversations.length === 0 ? (
                    <p className="p-6 text-center text-sm text-muted-foreground">{nothing(settled, data.filter, data.channel)}</p>
                ) : (
                    <ul className="m-0 min-h-0 flex-1 list-none divide-y divide-border overflow-y-auto p-0">
                        {data.conversations.map((row) => (
                            <li key={row.id}>
                                <button
                                    type="button"
                                    aria-current={open === row.id ? 'true' : undefined}
                                    onClick={() => go({ conversation: String(row.id) })}
                                    className={cx('flex w-full cursor-pointer items-start gap-3 border-0 px-3 py-3 text-start text-foreground', open === row.id ? 'bg-selected' : 'bg-transparent hover:bg-muted')}
                                >
                                    <Avatar row={row} />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-baseline justify-between gap-2">
                                            <span className={cx('truncate text-sm', row.unread > 0 ? 'font-bold' : 'font-medium')}>{row.name}</span>
                                            <span className="shrink-0 text-xs text-muted-foreground">{row.when}</span>
                                        </span>
                                        <span className="flex items-center justify-between gap-2">
                                            <span className={cx('truncate text-xs', row.unread === 0 && 'text-muted-foreground')}>{row.preview}</span>
                                            {row.unread > 0 && (
                                                <span className="shrink-0 rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground">
                                                    {row.unread}
                                                    <span className="sr-only"> unread</span>
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-1 flex flex-wrap items-center gap-1">
                                            {data.branch === 'all' && <span className={chip}>{row.branch}</span>}
                                            {row.channel === 'headsup' && <span className={chip}>Heads-up{row.about ? ` · ${row.about}` : ''}</span>}
                                            {row.channel === 'internal' && <span className={chip}>Internal{row.about ? ` · ${row.about}` : ''}</span>}
                                            {row.sample && <span className={cx(chip, '!bg-warning !text-warning-foreground')}>Sample</span>}
                                            {row.done && <span className={chip}>Done</span>}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {open ? (
                <Thread key={open} id={open} tick={tick} onBack={() => go({ conversation: null })} onChanged={changed} />
            ) : (
                <div className="hidden items-center justify-center rounded-xl border border-border bg-card p-6 text-sm text-muted-foreground lg:flex">Pick a chat on the left.</div>
            )}
        </div>
    );

    return (
        <>
            {header}
            {error && <ErrorState message={error} onRetry={reload} />}
            {data.notice && (
                <Alert tone="warning">{data.notice}</Alert>
            )}

            {data.tabs.length > 0 ? (
                <TabBar
                    label="Branches"
                    value={data.branch}
                    onChange={(branch) => go({ branch, conversation: null })}
                    tabs={data.tabs.map((tab) => ({ value: tab.key, label: tab.label, icon: 'store', badge: tab.unread }))}
                >
                    {body}
                </TabBar>
            ) : (
                body
            )}

            <NewThread
                open={starting}
                branches={data.branches}
                branch={/^\d+$/.test(data.branch) ? data.branch : data.branches.length === 1 ? data.branches[0].value : null}
                onClose={() => setStarting(false)}
                onStarted={(id) => {
                    setStarting(false);
                    go({ conversation: String(id), channel: 'internal', filter: null });
                    reload();
                }}
            />
        </>
    );
}
