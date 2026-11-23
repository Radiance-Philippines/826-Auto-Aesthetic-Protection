import { useLocalSearchParams } from 'expo-router';
import { UnitScreen } from '@/features/Unit';

export default function UnitRoute() {
    const { id } = useLocalSearchParams<{ id: string }>();

    return <UnitScreen id={Number(id)} />;
}
