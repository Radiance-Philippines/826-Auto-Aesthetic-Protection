import Constants from 'expo-constants';
import { useFocusEffect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Check, Download, FileText, KeyRound, LogOut, Save, Trash2, Undo2, UserRound } from 'lucide-react-native';
import { useCallback, useEffect, useState } from 'react';
import { Platform, View } from 'react-native';
import { api, problemText, type AppRelease } from '@/api';
import { useAuth } from '@/context/auth';
import { useCatalog } from '@/context/catalog';
import { PASSWORD_RULE, passwordProblem } from '@/lib/account';
import { DEMO } from '@/lib/config';
import { phoneOk } from '@/lib/wizard';
import { useTheme, Button, Card, Field, Screen, Sheet, FormError, Text } from '@826/core';
import { PasswordField } from '@/ui/PasswordField';

const METHODS = { password: 'email', google: 'Google', apple: 'Apple' } as const;

export default function MeScreen() {
    const c = useTheme();
    const { guest, setGuest, signOut, forget } = useAuth();
    const { catalog } = useCatalog();
    const [name, setName] = useState(guest?.name ?? '');
    const [phone, setPhone] = useState(guest?.phone ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [policy, setPolicy] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteBusy, setDeleteBusy] = useState(false);
    const [deleteProblem, setDeleteProblem] = useState<string | null>(null);
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({});
    const [changing, setChanging] = useState(false);
    const [changed, setChanged] = useState(false);
    const [release, setRelease] = useState<AppRelease | null>(null);
    const version = Constants.expoConfig?.version ?? '';
    const code = Constants.expoConfig?.android?.versionCode ?? 0;

    // The server may have filled in the number from a booking: show what it has
    useFocusEffect(
        useCallback(() => {
            void api.me().then((result) => {
                if (result.kind === 'ok') {
                    setGuest(result.data);
                    setName(result.data.name);
                    setPhone(result.data.phone ?? '');
                }
            });
        }, [setGuest]),
    );

    useEffect(() => {
        if (Platform.OS !== 'android') return;
        void api.latestRelease().then((result) => {
            if (result.kind === 'ok' && 'versionCode' in result.data && result.data.versionCode > code) setRelease(result.data as AppRelease);
        });
    }, [code]);

    const save = async () => {
        const found: Record<string, string> = {};
        if (!name.trim()) found.name = 'Add your name.';
        if (phone.trim() && !phoneOk(phone)) found.phone = 'Enter your mobile number, like 0917 123 4567.';
        setErrors(found);
        if (Object.keys(found).length) return;

        setSaving(true);
        const result = await api.updateMe({ name: name.trim(), ...(phone.trim() ? { phone: phone.trim() } : {}) });
        setSaving(false);

        if (result.kind === 'ok') {
            setGuest(result.data);
            setSaved(true);
            setTimeout(() => setSaved(false), 2500);
        } else if (result.kind === 'invalid') {
            setErrors(Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key, messages[0]])));
        } else {
            setErrors({ form: problemText(result) });
        }
    };

    const changePassword = async () => {
        const found: Record<string, string> = {};
        if (!current) found.current_password = 'Enter your current password.';
        const weak = passwordProblem(next, guest?.email ?? '');
        if (weak) found.password = weak;
        setPasswordErrors(found);
        setChanged(false);
        if (Object.keys(found).length) return;

        setChanging(true);
        const result = await api.updateMe({ current_password: current, password: next });
        setChanging(false);

        if (result.kind === 'ok') {
            setGuest(result.data);
            setCurrent('');
            setNext('');
            setChanged(true);
        } else if (result.kind === 'invalid') {
            setPasswordErrors(Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key, messages[0]])));
        } else {
            setPasswordErrors({ form: problemText(result) });
        }
    };

    const deleteAccount = async () => {
        setDeleteBusy(true);
        setDeleteProblem(null);
        const result = await api.deleteAccount();
        setDeleteBusy(false);

        if (result.kind === 'ok') {
            setDeleting(false);
            await forget();
        } else {
            // 409 carries the plain reason, e.g. a unit is still in the shop
            setDeleteProblem(problemText(result));
        }
    };

    return (
        <Screen brand title="Me" tabs>
            <Card style={{ gap: 14 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                    <View style={{ width: 48, height: 48, borderRadius: 24, backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' }}>
                        <UserRound size={24} color={c.muted} />
                    </View>
                    <View style={{ flex: 1 }}>
                        <Text v="title" numberOfLines={1}>
                            {guest?.name}
                        </Text>
                        {guest?.email ? <Text v="small">{guest.email}</Text> : null}
                        {guest?.signInMethod && METHODS[guest.signInMethod] ? <Text v="small">{`Signed in with ${METHODS[guest.signInMethod]}`}</Text> : null}
                    </View>
                </View>
                <Field label="Name" value={name} onChangeText={setName} autoCapitalize="words" error={errors.name} />
                <Field label="Mobile number" value={phone} onChangeText={setPhone} keyboardType="phone-pad" placeholder="0917 123 4567" error={errors.phone} />
                <FormError>{errors.form}</FormError>
                <Button icon={saved ? Check : Save} label={saved ? 'Saved' : 'Save'} loading={saving} onPress={save} />
            </Card>

            {guest?.signInMethod === 'password' ? (
                <Card style={{ gap: 12 }} testID="change-password">
                    <Text v="title">Change password</Text>
                    <PasswordField label="Current password" value={current} onChangeText={setCurrent} autoComplete="current-password" textContentType="password" error={passwordErrors.current_password} />
                    <PasswordField label="New password" value={next} onChangeText={setNext} autoComplete="new-password" textContentType="newPassword" hint={PASSWORD_RULE} error={passwordErrors.password} />
                    <FormError>{passwordErrors.form}</FormError>
                    {changed ? (
                        <Text v="small" accessibilityLiveRegion="polite">
                            Password changed. Your other devices are signed out.
                        </Text>
                    ) : null}
                    <Button kind="secondary" icon={changed ? Check : KeyRound} label="Change password" loading={changing} onPress={() => void changePassword()} testID="change-password-button" />
                </Card>
            ) : null}

            {release ? (
                <Card style={{ gap: 8 }}>
                    <Text v="title">{`Version ${release.version} is out`}</Text>
                    {release.notes ? <Text v="small">{release.notes}</Text> : null}
                    <Button kind="secondary" icon={Download} label="Download the update" onPress={() => void WebBrowser.openBrowserAsync(release.url)} />
                </Card>
            ) : null}

            {catalog?.booking.policy ? <Button kind="secondary" icon={FileText} label="Booking policy" onPress={() => setPolicy(true)} /> : null}
            <Sheet open={policy} onClose={() => setPolicy(false)} title="Booking policy">
                <Text>{catalog?.booking.policy}</Text>
            </Sheet>

            <Button kind="secondary" icon={LogOut} label="Sign out" onPress={() => void signOut()} testID="sign-out" />
            <Button kind="danger" icon={Trash2} label="Delete my account" onPress={() => { setDeleteProblem(null); setDeleting(true); }} testID="delete-account" />

            <Text v="small" style={{ textAlign: 'center' }}>
                {`826 Auto Aesthetic · version ${version}${DEMO ? ' · demo' : ''}`}
            </Text>

            <Sheet open={deleting} onClose={() => setDeleting(false)} title="Delete my account?">
                <Text color={c.muted}>This removes your name, email, number, referral code and sign-in from 826. Your past bookings stay with the shop for its records, but no longer carry your name. This can’t be undone.</Text>
                <FormError>{deleteProblem}</FormError>
                <Button kind="danger" icon={Trash2} label="Yes, delete my account" loading={deleteBusy} onPress={deleteAccount} testID="confirm-delete" />
                <Button kind="secondary" icon={Undo2} label="Keep my account" onPress={() => setDeleting(false)} />
            </Sheet>
        </Screen>
    );
}
