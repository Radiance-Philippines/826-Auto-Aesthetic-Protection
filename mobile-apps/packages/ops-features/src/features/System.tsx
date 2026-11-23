import { Check, X } from 'lucide-react-native';
import { View } from 'react-native';
import { api } from '../api';
import { useUser } from '../context/auth';
import { stamp, useLoad, c, Card, ErrorState, Screen, Skeleton, Text } from '@826/core';
import { RoleChip } from '../ui/Header';

const HEALTH_LABELS: Record<string, string> = { email: 'Email', meta: 'Facebook and Instagram', google: 'Google sign-in', flow: 'Radiance Flow' };

function Row({ label, on, text }: { label: string; on?: boolean; text?: string }) {
    return (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }} accessible accessibilityLabel={`${label}: ${text ?? (on ? 'on' : 'off')}`}>
            <Text weight="semi" style={{ flex: 1 }}>
                {label}
            </Text>
            {text !== undefined ? (
                <Text v="small">{text}</Text>
            ) : on ? (
                <Check size={18} color={c.tone.green.fg} />
            ) : (
                <X size={18} color={c.danger} />
            )}
        </View>
    );
}

/** 826 System's one tab: what is switched on, the live app versions and the security switches. Yes or no and versions only, the server never sends a key or a password. */
export function SystemScreen() {
    const user = useUser();
    const { data, error, loading, reload } = useLoad(() => api.system());

    return (
        <Screen tabs top={<RoleChip user={user} />} title="System" onRefresh={reload}>
            {loading ? (
                <>
                    <Skeleton height={140} style={{ borderRadius: 16 }} />
                    <Skeleton height={140} style={{ borderRadius: 16 }} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    <Card style={{ gap: 12 }}>
                        <Text v="title">Connections</Text>
                        {Object.entries(data.health).map(([key, on]) => (
                            <Row key={key} label={HEALTH_LABELS[key] ?? key} on={on} />
                        ))}
                    </Card>
                    <Card style={{ gap: 12 }}>
                        <Text v="title">Apps</Text>
                        <Row label="826 customer app" text={data.customerApp ? `${data.customerApp.version}${data.customerApp.publishedAt ? ` · ${stamp(data.customerApp.publishedAt) ?? ''}` : ''}` : 'No build yet'} />
                        {data.teamApps.map((app) => (
                            <Row key={app.app} label={app.label} text={app.version ?? 'No build yet'} />
                        ))}
                    </Card>
                    <Card style={{ gap: 12 }}>
                        <Text v="title">Security</Text>
                        <Row label="Two-step check required" on={data.security.requireTwoFactor} />
                        <Row label="Passkeys allowed" on={data.security.passkeysAllowed} />
                    </Card>
                </>
            ) : null}
        </Screen>
    );
}
