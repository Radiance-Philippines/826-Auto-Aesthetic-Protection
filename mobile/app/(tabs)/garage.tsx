import { useRouter } from 'expo-router';
import { CalendarPlus, Plus, ShieldCheck, Star } from 'lucide-react-native';
import { View } from 'react-native';
import { api, type CustomerBooking, type SavedVehicle } from '@/api';
import { VehicleHead } from '@/features/VehicleCard';
import { useLoad } from '@/hooks/useLoad';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Screen } from '@/ui/Screen';
import { Enter, PressScale } from '@/ui/motion';
import { Skeleton } from '@/ui/Skeleton';
import { EmptyState, ErrorState } from '@/ui/States';
import { Text } from '@/ui/Text';
import { useTheme } from '@/context/theme';


type Garage = { vehicles: SavedVehicle[]; bookings: CustomerBooking[] };

async function loadGarage() {
    const [vehicles, bookings] = await Promise.all([api.vehicles(), api.bookings()]);

    if (vehicles.kind !== 'ok') return vehicles;
    if (bookings.kind !== 'ok') return bookings;

    return { kind: 'ok' as const, data: { vehicles: vehicles.data.vehicles, bookings: bookings.data.bookings } };
}

/** The unit's latest finished work, from the server's own field or from its exited bookings */
function withLastService(vehicle: SavedVehicle, bookings: CustomerBooking[]): SavedVehicle {
    if (vehicle.lastService) return vehicle;

    const last = bookings.filter((b) => b.vehicle.id === vehicle.id && b.stage === 'exited').sort((a, b) => (b.exitedAt ?? '').localeCompare(a.exitedAt ?? ''))[0];

    return last ? { ...vehicle, lastService: { name: last.items.map((i) => i.name).join(', '), on: (last.exitedAt ?? last.dropOffOn ?? '').slice(0, 10) } } : vehicle;
}

export default function GarageScreen() {
    const c = useTheme();
    const router = useRouter();
    const { data, error, loading, reload } = useLoad<Garage>(loadGarage);

    return (
        <Screen brand title="My garage" onRefresh={reload} tabs>
            {loading ? (
                <>
                    <Skeleton height={140} />
                    <Skeleton height={140} />
                </>
            ) : error ? (
                <ErrorState result={error} onRetry={reload} />
            ) : data ? (
                <>
                    {data.vehicles.length === 0 ? <EmptyState title="No units yet" body="A unit you book is kept here for next time." action={{ label: 'Book a service', icon: CalendarPlus, onPress: () => router.push('/book') }} /> : null}
                    {data.vehicles.map((raw, index) => {
                        const vehicle = withLastService(raw, data.bookings);
                        const reviewable = data.bookings.find((b) => b.vehicle.id === vehicle.id && b.canReview);

                        return (
                            <Enter key={vehicle.id} index={index}>
                                <Card style={{ gap: 14, padding: 16 }}>
                                    <PressScale accessibilityRole="button" accessibilityLabel={`${vehicle.model}, open unit`} onPress={() => router.push(`/vehicle/${vehicle.id}`)}>
                                        <VehicleHead vehicle={vehicle} />
                                    </PressScale>
                                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                                        <Button small icon={CalendarPlus} label="Book again" onPress={() => router.push({ pathname: '/book', params: { vehicleId: String(vehicle.id) } })} />
                                        <Button small kind="secondary" icon={ShieldCheck} label="Warranty" onPress={() => router.push({ pathname: '/warranty', params: { vehicleId: String(vehicle.id) } })} />
                                        {reviewable ? <Button small kind="link" icon={Star} label="Review" onPress={() => router.push(`/review/${reviewable.id}`)} /> : null}
                                    </View>
                                </Card>
                            </Enter>
                        );
                    })}
                    <Card style={{ gap: 10 }}>
                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                            <Plus size={18} color={c.accent} />
                            <Text weight="bold">Add a unit</Text>
                        </View>
                        <Text v="small">A new unit is saved when you book it: add its year, model, type and plate in the booking.</Text>
                        <Button kind="secondary" icon={Plus} label="Book with a new unit" onPress={() => router.push('/book')} />
                    </Card>
                </>
            ) : null}
        </Screen>
    );
}
