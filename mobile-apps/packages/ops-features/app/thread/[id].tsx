import { useLocalSearchParams } from 'expo-router';
import { ThreadScreen } from '../../src/features/Inbox';

export default function ThreadRoute() {
    const { id } = useLocalSearchParams<{ id: string }>();

    return <ThreadScreen id={Number(id)} />;
}
