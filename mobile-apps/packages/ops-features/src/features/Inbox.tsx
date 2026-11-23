import { useRouter } from 'expo-router';
import { Check, RotateCcw, Search, Send } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Pressable, TextInput, View } from 'react-native';
import { api, problemText, type Message, type ConversationRow } from '../api';
import { useUser } from '../context/auth';
import { useLoad, shortStamp, c, fonts, radius, target, Button, Card, Chip, Field, Screen, Skeleton, EmptyState, ErrorState, FormError, Text } from '@826/core';
import { usableQuickReplies } from '../lib/quick';
import { initials } from '../lib/unit';
import { RoleChip } from '../ui/Header';
import { Avatar, Pills } from '../ui/Pills';

const FILTERS = [
    { key: 'open', label: 'Open' },
    { key: 'unread', label: 'Unread' },
    { key: 'done', label: 'Done' },
    { key: 'all', label: 'All' },
];

/** The inbox: one tab per branch the person may see, newest first. Privacy is the server's: another branch's chats never arrive. */
export function InboxScreen() {
    const user = useUser();
    const router = useRouter();
    const [branch, setBranch] = useState<string | undefined>(undefined);
    const [filter, setFilter] = useState('open');
    const [text, setText] = useState('');
    const [q, setQ] = useState('');
    const { data, error, loading, reload } = useLoad(() => api.inbox(branch ?? 'all', filter, q), `${branch}|${filter}|${q}`);

    useEffect(() => {
        const timer = setTimeout(() => setQ(text.trim()), 350);

        return () => clearTimeout(timer);
    }, [text]);

    // The server's tabs say which branches (and whether All) this person has
    const tabs = data?.tabs ?? [];
    const active = branch ?? data?.branch ?? 'all';

    return (
        <Screen tabs top={<RoleChip user={user} />} title="Inbox" onRefresh={reload}>
            {data?.notice ? <Text color={c.gold}>{data.notice}</Text> : null}
            {tabs.length > 1 ? <Pills label="Branch" items={tabs.map((tab) => ({ key: tab.key, label: tab.label, count: tab.unread }))} value={active} onChange={setBranch} /> : null}
            <Pills label="Filter" items={FILTERS} value={filter} onChange={setFilter} />
            <Field label="Search" icon={Search} value={text} onChangeText={setText} placeholder="Name or message" autoCapitalize="none" autoCorrect={false} />
            {loading ? (
                <>
                    <Skeleton height={72} style={{ borderRadius: 16 }} />
                    <Skeleton height={72} style={{ borderRadius: 16 }} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data && data.conversations.length === 0 ? (
                <EmptyState title="No chats" body={filter === 'open' ? 'Nothing waiting for a reply.' : 'No chats match.'} />
            ) : (
                data?.conversations.map((row) => (
                    <Pressable key={row.id} accessibilityRole="button" accessibilityLabel={`${row.name}${row.unread ? ', unread' : ''}. ${row.preview ?? ''}`} onPress={() => router.push(`/thread/${row.id}`)}>
                        <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                            <Avatar text={initials(row.name)} />
                            <View style={{ flex: 1, gap: 2 }}>
                                <Text v="title" numberOfLines={1}>
                                    {row.name}
                                </Text>
                                {row.preview ? (
                                    <Text v="small" numberOfLines={1}>
                                        {row.preview}
                                    </Text>
                                ) : null}
                            </View>
                            <View style={{ alignItems: 'flex-end', gap: 4 }}>
                                {row.unread > 0 ? <Chip label={`Unread ${row.unread}`} tone="teal" /> : row.done ? <Chip label="Done" tone="neutral" /> : null}
                                {row.lastMessageAt ? <Text v="small">{shortStamp(row.lastMessageAt)}</Text> : null}
                            </View>
                        </Card>
                    </Pressable>
                ))
            )}
        </Screen>
    );
}

/** S5 (inbox style): one chat, quick replies, the composer, mark done */
export function ThreadScreen({ id }: { id: number }) {
    const { data, error, loading, reload } = useLoad(() => api.thread(id));
    const quick = useLoad(() => api.quickReplies(id));
    const [sent, setSent] = useState<Message[]>([]);
    const [row, setRow] = useState<ConversationRow | null>(null);
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    if (!data) {
        return (
            <Screen back="Inbox" onRefresh={reload}>
                {loading ? <Skeleton height={200} style={{ borderRadius: 16 }} /> : error ? <ErrorState result={error} onRetry={reload} /> : null}
            </Screen>
        );
    }

    const conversation = { ...data.conversation, ...row };
    const messages = [...data.messages, ...sent.filter((message) => !data.messages.some((m) => m.id === message.id))];

    const send = async (text: string) => {
        if (!text.trim()) return;
        setBusy(true);
        setProblem(null);
        const result = await api.reply(id, text.trim());
        setBusy(false);

        if (result.kind === 'ok') {
            setSent((previous) => [...previous, result.data.message]);
            setRow(result.data.conversation);
            setBody('');
        } else {
            setProblem(problemText(result));
        }
    };

    const toggleDone = async () => {
        setBusy(true);
        setProblem(null);
        const result = await api.setDone(id, !conversation.done);
        setBusy(false);

        if (result.kind === 'ok') setRow(result.data);
        else setProblem(problemText(result));
    };

    const replies = usableQuickReplies(quick.data?.replies);

    return (
        <Screen
            back="Inbox"
            title={conversation.name}
            subtitle={[conversation.branch?.name, conversation.sample ? 'Sample chat' : 'Messenger'].filter(Boolean).join(' · ')}
            onRefresh={async () => {
                setSent([]);
                setRow(null);
                await reload();
            }}
            footer={
                <View style={{ gap: 10 }}>
                    {replies.length ? (
                        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                            {replies.map((reply) => (
                                <Pressable key={reply.key} accessibilityRole="button" accessibilityLabel={`Quick reply: ${reply.label}`} onPress={() => setBody(reply.text)} style={{ minHeight: target - 8, paddingHorizontal: 14, borderRadius: radius.pill, justifyContent: 'center', backgroundColor: c.tone.teal.bg }}>
                                    <Text weight="semi" color={c.accent} style={{ fontSize: 14 }}>
                                        {reply.label}
                                    </Text>
                                </Pressable>
                            ))}
                        </View>
                    ) : null}
                    <FormError>{problem}</FormError>
                    <View style={{ flexDirection: 'row', alignItems: 'flex-end', gap: 10 }}>
                        <TextInput
                            accessibilityLabel="Reply"
                            value={body}
                            onChangeText={setBody}
                            placeholder={`Reply to ${conversation.name.split(' ')[0]}`}
                            placeholderTextColor={c.field}
                            selectionColor={c.accent}
                            multiline
                            style={{ flex: 1, minHeight: target, maxHeight: 120, borderRadius: radius.lg, borderWidth: 1, borderColor: c.field, backgroundColor: c.surface, color: c.text, fontFamily: fonts.regular, fontSize: 16, paddingHorizontal: 14, paddingVertical: 12 }}
                        />
                        <Pressable accessibilityRole="button" accessibilityLabel="Send" accessibilityState={{ disabled: busy || !body.trim() }} disabled={busy || !body.trim()} onPress={() => void send(body)} style={{ width: target + 4, height: target + 4, borderRadius: (target + 4) / 2, alignItems: 'center', justifyContent: 'center', backgroundColor: c.accent, opacity: busy || !body.trim() ? 0.5 : 1 }}>
                            <Send size={22} color={c.onAccent} />
                        </Pressable>
                    </View>
                </View>
            }
        >
            {conversation.savesLocallyOnly ? <Chip label="Sample chat: replies are saved here only" tone="gold" /> : null}
            {conversation.pastReplyWindow ? <Text color={c.gold}>It has been over {conversation.windowHours} hours since this customer wrote. Messenger may not deliver a reply.</Text> : null}
            <Button small kind="secondary" icon={conversation.done ? RotateCcw : Check} label={conversation.done ? 'Reopen' : 'Mark done'} loading={busy} onPress={toggleDone} />
            {data.hasOlder ? <Text v="small">Older messages are not shown here.</Text> : null}
            {messages.length === 0 ? <Text color={c.muted}>No messages yet.</Text> : null}
            {messages.map((message) => (
                <View key={message.id} style={{ alignSelf: message.direction === 'out' ? 'flex-end' : 'flex-start', maxWidth: '82%', backgroundColor: message.direction === 'out' ? 'rgba(42,211,203,0.16)' : c.surface, borderWidth: 1, borderColor: message.direction === 'out' ? 'rgba(42,211,203,0.3)' : c.line, borderRadius: radius.lg, padding: 12, gap: 4 }}>
                    {message.body ? <Text>{message.body}</Text> : <Text color={c.muted}>Attachment</Text>}
                    <Text v="small" color={message.failed ? c.danger : c.muted}>
                        {message.failed ? (message.error ?? 'Not sent') : shortStamp(message.sentAt)}
                    </Text>
                </View>
            ))}
        </Screen>
    );
}
