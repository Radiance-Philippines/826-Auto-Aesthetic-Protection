import { Image } from 'expo-image';
import * as Clipboard from 'expo-clipboard';
import { Copy, ImagePlus, Send } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Pressable, View } from 'react-native';
import { api, problemText, type CustomerBooking, type PaymentMethodKey, type UploadFile } from '@/api';
import { minutesUntil } from '@/lib/dates';
import { peso } from '@/lib/money';
import { pickPhoto } from '@/lib/photo';
import { c, radius, target } from '@/theme';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { FormError } from '@/ui/States';
import { Text } from '@/ui/Text';

/**
 * Paying the reservation fee: pick GCash or Maya, pay in that app, then send
 * the screenshot (the same steps as the site's PaymentPanel). The branch
 * checks it and confirms the date.
 */
export function PaymentPanel({ booking, onUpdated }: { booking: CustomerBooking; onUpdated: (booking: CustomerBooking) => void }) {
    const [method, setMethod] = useState<PaymentMethodKey | null>(booking.methods[0]?.key ?? null);
    const [file, setFile] = useState<UploadFile | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [copied, setCopied] = useState<string | null>(null);
    const [now, setNow] = useState(Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 30000);

        return () => clearInterval(timer);
    }, []);

    const chosen = booking.methods.find((m) => m.key === method);
    const minutes = minutesUntil(booking.holdExpiresAt, now);

    const copy = async (what: string, value: string) => {
        await Clipboard.setStringAsync(value);
        setCopied(what);
        setTimeout(() => setCopied(null), 2000);
    };

    const choose = async () => {
        const picked = await pickPhoto();

        if (picked) {
            setFile(picked);
            setProblem(null);
        }
    };

    const send = async () => {
        if (!file || !method) {
            setProblem('Add a screenshot of your payment.');

            return;
        }

        setBusy(true);
        const result = await api.pay(booking.id, method, file);
        setBusy(false);

        if (result.kind === 'ok') {
            onUpdated(result.data.booking);
        } else {
            setProblem(problemText(result));
        }
    };

    return (
        <Card style={{ gap: 14 }}>
            <Text v="title">How to pay</Text>
            {booking.payment.rejectedReason ? (
                <View style={{ backgroundColor: 'rgba(255,158,146,0.12)', borderRadius: radius.md, padding: 12, gap: 4 }}>
                    <Text weight="bold" color={c.danger}>
                        Your last screenshot was turned down
                    </Text>
                    <Text style={{ fontSize: 14 }}>{booking.payment.rejectedReason}</Text>
                    <Text v="small">Pay again if needed and send a new screenshot.</Text>
                </View>
            ) : null}
            {booking.holdExpiresAt ? (
                <Text accessibilityLiveRegion="polite" color={minutes <= 5 ? c.danger : c.gold} weight="semi">
                    {minutes === 0 ? 'Your hold has run out.' : minutes <= 1 ? 'Less than a minute left to pay' : `${minutes} minutes left to pay`}
                </Text>
            ) : null}
            {booking.amountDue !== null ? (
                <View>
                    <Text v="label">Reservation fee</Text>
                    <Text v="number">{peso(booking.amountDue)}</Text>
                    {booking.price.total !== null ? <Text v="small">{`Total for the work: ${peso(booking.price.total)}.`}</Text> : null}
                </View>
            ) : null}

            {booking.methods.length === 0 ? (
                <Text color={c.muted}>The branch hasn’t added its GCash or Maya account yet. Call or message them to pay the fee.</Text>
            ) : (
                <>
                    <View accessibilityRole="radiogroup" style={{ flexDirection: 'row', gap: 8 }}>
                        {booking.methods.map((m) => (
                            <Pressable key={m.key} accessibilityRole="radio" accessibilityState={{ selected: method === m.key }} onPress={() => setMethod(m.key)} style={{ flex: 1, minHeight: target, borderRadius: radius.md, borderWidth: 1, borderColor: method === m.key ? c.accent : c.line, backgroundColor: method === m.key ? 'rgba(42,211,203,0.12)' : c.surface2, alignItems: 'center', justifyContent: 'center' }}>
                                <Text weight="semi" color={method === m.key ? c.accent : c.text}>
                                    {m.label}
                                </Text>
                            </Pressable>
                        ))}
                    </View>
                    {chosen ? (
                        <View style={{ gap: 10 }}>
                            <Text color={c.muted}>{`Pay with ${chosen.label}`}</Text>
                            {chosen.qr ? <Image source={{ uri: chosen.qr }} accessibilityLabel={`${chosen.label} QR code for paying the reservation fee`} style={{ width: 200, height: 200, borderRadius: radius.md, alignSelf: 'center', backgroundColor: '#fff' }} contentFit="contain" /> : <Text v="small">{`No ${chosen.label} QR yet. Send the fee to the account below.`}</Text>}
                            {chosen.accountName ? <Detail label="Account name" value={chosen.accountName} onCopy={() => copy('name', chosen.accountName as string)} copied={copied === 'name'} /> : null}
                            {chosen.accountNumber ? <Detail label="Account number" value={chosen.accountNumber} onCopy={() => copy('number', chosen.accountNumber as string)} copied={copied === 'number'} /> : null}
                        </View>
                    ) : null}
                    {booking.instructions ? <Text v="small">{booking.instructions}</Text> : null}

                    <Text v="label">Send your screenshot</Text>
                    {file ? <Image source={{ uri: file.uri }} accessibilityLabel="The screenshot you picked" style={{ width: '100%', height: 180, borderRadius: radius.md }} contentFit="contain" /> : null}
                    <Button kind="secondary" icon={ImagePlus} label={file ? 'Choose a different screenshot' : 'Choose a screenshot'} onPress={choose} />
                    <Text v="small">A JPG, PNG or WebP image showing the amount and reference number.</Text>
                    <FormError>{problem}</FormError>
                    <Button icon={Send} label="Send screenshot" loading={busy} disabled={!file} onPress={send} testID="send-proof" />
                </>
            )}
        </Card>
    );
}

function Detail({ label, value, onCopy, copied }: { label: string; value: string; onCopy: () => void; copied: boolean }) {
    return (
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
            <View style={{ flexShrink: 1 }}>
                <Text v="small">{label}</Text>
                <Text weight="bold" style={{ fontSize: 16 }} selectable>
                    {value}
                </Text>
            </View>
            <Button small kind="secondary" icon={Copy} label={copied ? 'Copied' : 'Copy'} accessibilityLabel={`Copy ${label}`} onPress={onCopy} />
        </View>
    );
}
