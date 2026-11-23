import { useRouter } from 'expo-router';
import { ChevronRight } from 'lucide-react-native';
import { View } from 'react-native';
import type { CustomerBooking } from '@/api';
import { shortDate } from '@/lib/dates';
import { STAGES } from '@/lib/stage';
import { bookingUnit, serviceLine } from '@/lib/unit';
import { radius } from '@/theme';
import { useTheme } from '@/context/theme';
import { Chip } from '@/ui/Chip';
import { PressScale } from '@/ui/motion';
import { Silhouette } from '@/ui/Silhouette';
import { Text } from '@/ui/Text';

/** One booking in a list: the unit, what is being done, the stage and the date. Opens the booking. */
export function BookingCard({ booking }: { booking: CustomerBooking }) {
    const c = useTheme();
    const router = useRouter();
    const unit = bookingUnit(booking);
    const stage = STAGES[booking.stage];
    const date = booking.kind === 'home' ? (booking.preferredDate ? `You asked for ${shortDate(booking.preferredDate)}` : 'Home service') : booking.dropOffOn ? `Drop-off ${shortDate(booking.dropOffOn)}` : null;

    return (
        <PressScale
            accessibilityRole="button"
            accessibilityLabel={`${unit.name}, ${serviceLine(booking)}, ${stage.label}${date ? `, ${date}` : ''}. Open booking`}
            onPress={() => router.push(`/booking/${booking.id}`)}
            style={{ backgroundColor: c.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: c.line, padding: 14, gap: 12 }}
        >
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <Silhouette type={booking.vehicle.type} width={72} height={44} />
                <View style={{ flex: 1, gap: 2 }}>
                    <Text v="title" numberOfLines={2}>
                        {unit.name}
                    </Text>
                    <Text v="small" numberOfLines={2}>
                        {serviceLine(booking) || unit.sub}
                    </Text>
                </View>
                <ChevronRight size={20} color={c.muted} />
            </View>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
                <Chip label={stage.label} tone={stage.tone} />
                {date ? <Text v="small">{date}</Text> : null}
            </View>
        </PressScale>
    );
}
