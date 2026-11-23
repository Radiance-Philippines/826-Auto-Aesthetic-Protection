import { Clock } from 'lucide-react-native';
import { View } from 'react-native';
import type { SavedVehicle } from '@/api';
import { dayMonthYear } from '@/lib/dates';
import { savedUnit } from '@/lib/unit';
import { useTheme } from '@/context/theme';
import { Silhouette } from '@/ui/Silhouette';
import { Text } from '@/ui/Text';

/** A saved unit's picture, name, type and plate, and its last finished work when the server sends it */
export function VehicleHead({ vehicle, width = 96 }: { vehicle: SavedVehicle; width?: number }) {
    const c = useTheme();
    const unit = savedUnit(vehicle);

    return (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
            <Silhouette type={vehicle.type} width={width} height={Math.round(width * 0.6)} />
            <View style={{ flex: 1, gap: 2 }}>
                <Text v="title" numberOfLines={2}>
                    {unit.name}
                </Text>
                <Text v="small">{unit.sub}</Text>
                {vehicle.lastService ? (
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 2 }}>
                        <Clock size={13} color={c.steel} />
                        <Text v="small" style={{ flexShrink: 1 }}>
                            {vehicle.lastService.name} · {dayMonthYear(vehicle.lastService.on)}
                        </Text>
                    </View>
                ) : null}
            </View>
        </View>
    );
}
