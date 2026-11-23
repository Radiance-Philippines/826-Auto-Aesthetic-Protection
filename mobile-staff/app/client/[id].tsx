import { useLocalSearchParams } from 'expo-router';
import { ClientScreen } from '@/features/Clients';

export default function ClientRoute() {
    const { id } = useLocalSearchParams<{ id: string }>();

    return <ClientScreen id={Number(id)} />;
}
