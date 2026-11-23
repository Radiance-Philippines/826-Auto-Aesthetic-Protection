import { SignInScreen } from '../src/features/SignIn';
import { useAuth } from '../src/context/auth';

export default function SignIn() {
    const { accept } = useAuth();

    return <SignInScreen onDone={accept} />;
}
