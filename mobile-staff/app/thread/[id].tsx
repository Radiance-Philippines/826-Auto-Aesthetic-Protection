import { useLocalSearchParams } from 'expo-router';
import { ThreadScreen } from '@/features/Inbox';

export default function ThreadRoute() {
    const { id } = useLocalSearchParams<{ id: string }>();

    return <ThreadScreen id={Number(id)} />;
}
