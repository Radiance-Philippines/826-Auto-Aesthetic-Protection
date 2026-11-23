import { Redirect } from 'expo-router';
import { useAuth } from '../src/context/auth';
import { firstTab } from '../src/lib/tabs';

/** Signed out: the sign-in screen (the tabs are protected, so a redirect there shows nothing). Signed in: the first tab the server gave this person */
export default function Index() {
    const { user } = useAuth();

    return <Redirect href={user ? `/${firstTab(user.tabs)}` : '/sign-in'} />;
}
