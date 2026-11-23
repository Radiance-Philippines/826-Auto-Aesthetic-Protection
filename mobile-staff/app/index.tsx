import { Redirect } from 'expo-router';
import { useAuth } from '@/context/auth';
import { firstTab } from '@/lib/tabs';

/** The first tab the server gave this person */
export default function Index() {
    const { user } = useAuth();

    return <Redirect href={`/${firstTab(user?.tabs)}`} />;
}
