import { useRouter } from 'expo-router';
import { CalendarPlus } from 'lucide-react-native';
import { api, type CustomerBooking } from '@/api';
import { BookingCard } from '@/features/BookingCard';
import { useLoad } from '@/hooks/useLoad';
import { isEnded } from '@/lib/stage';
import { Enter } from '@/ui/motion';
import { Screen } from '@/ui/Screen';
import { Skeleton } from '@/ui/Skeleton';
import { EmptyState, ErrorState } from '@/ui/States';
import { Text } from '@/ui/Text';

export default function BookingsScreen() {
    const router = useRouter();
    const { data, error, loading, reload } = useLoad<CustomerBooking[], { bookings: CustomerBooking[] }>(() => api.bookings(), (raw) => raw.bookings);
    const upcoming = data?.filter((b) => !isEnded(b.stage)) ?? [];
    const past = data?.filter((b) => isEnded(b.stage)) ?? [];

    return (
        <Screen back="Home" title="My bookings" onRefresh={reload}>
            {loading ? (
                <>
                    <Skeleton height={110} />
                    <Skeleton height={110} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    {data.length === 0 ? <EmptyState title="No bookings yet." body="Your reservations and home service requests show here." action={{ label: 'Reserve a drop-off date', icon: CalendarPlus, onPress: () => router.push('/book') }} /> : null}
                    {upcoming.length ? <Text v="label">Coming up</Text> : null}
                    {upcoming.map((b, index) => (
                        <Enter key={b.id} index={index}>
                            <BookingCard booking={b} />
                        </Enter>
                    ))}
                    {past.length ? <Text v="label">Past and cancelled</Text> : null}
                    {past.map((b, index) => (
                        <Enter key={b.id} index={upcoming.length + index}>
                            <BookingCard booking={b} />
                        </Enter>
                    ))}
                </>
            ) : null}
        </Screen>
    );
}
