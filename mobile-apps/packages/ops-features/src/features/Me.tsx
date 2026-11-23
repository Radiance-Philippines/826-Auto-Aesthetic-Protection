import Constants from 'expo-constants';
import { Download, LogOut, Users } from 'lucide-react-native';
import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Linking, Platform, View } from 'react-native';
import { api, type AppRelease } from '../api';
import { useAuth } from '../context/auth';
import { roleChip } from '../lib/access';
import { initials } from '../lib/unit';
import { c, Button, Card, Screen, Text } from '@826/core';
import { Avatar } from '../ui/Pills';

export const appVersion = (): string => Constants.expoConfig?.version ?? '0.1.0';
const versionCode = (): number => Number(Constants.expoConfig?.android?.versionCode ?? 1);

export function MeScreen() {
    const { user, signOut, refresh } = useAuth();
    const router = useRouter();
    const [release, setRelease] = useState<AppRelease | null>(null);
    const [checked, setChecked] = useState(false);
    const [busy, setBusy] = useState(false);

    const check = async () => {
        const result = await api.latestRelease();
        setChecked(true);
        setRelease(result.kind === 'ok' && 'versionCode' in result.data && result.data.url && result.data.versionCode > versionCode() ? (result.data as AppRelease) : null);
    };

    useEffect(() => {
        if (Platform.OS === 'android') void check();
    }, []);

    if (!user) return null;

    return (
        <Screen tabs brand title="Me" onRefresh={async () => { await refresh(); await check(); }}>
            <Card style={{ gap: 14 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                    <Avatar text={initials(user.name)} size={52} />
                    <View style={{ flex: 1 }}>
                        <Text v="title">{user.name}</Text>
                        <Text v="small">{user.email}</Text>
                    </View>
                </View>
                <View style={{ gap: 4 }}>
                    <Text v="label">Role</Text>
                    <Text>{user.roleLabel}</Text>
                </View>
                <View style={{ gap: 4 }}>
                    <Text v="label">{user.branches.length === 1 ? 'Branch' : 'Branches'}</Text>
                    <Text>{user.isOwner && user.branches.length > 1 ? `All branches: ${user.branches.map((b) => b.name).join(', ')}` : user.branches.map((b) => b.name).join(', ') || 'None assigned'}</Text>
                </View>
                <Text v="small" accessibilityLabel={`Signed in as ${roleChip(user)}`}>
                    What you see depends on your role. Ask the owner if something is missing.
                </Text>
            </Card>

            {user.overflow?.includes('clients') ? <Button kind="secondary" icon={Users} label="Clients" onPress={() => router.push('/clients')} /> : null}

            <Card style={{ gap: 10 }}>
                <Text v="label">App</Text>
                <Text>{`826 Team ${appVersion()}`}</Text>
                {release ? (
                    <>
                        <Text color={c.accent}>Version {release.version} is available.</Text>
                        {release.notes ? <Text v="small">{release.notes}</Text> : null}
                        <Button icon={Download} label="Download the update" onPress={() => release.url && void Linking.openURL(release.url)} />
                    </>
                ) : checked ? (
                    <Text v="small">You have the latest version.</Text>
                ) : null}
            </Card>

            <Button
                kind="danger"
                icon={LogOut}
                label="Sign out"
                loading={busy}
                onPress={async () => {
                    setBusy(true);
                    await signOut();
                }}
            />
        </Screen>
    );
}
