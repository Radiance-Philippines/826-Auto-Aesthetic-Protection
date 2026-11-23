import { SignInScreen } from '@/features/SignIn';
import { useAuth } from '@/context/auth';

export default function SignIn() {
    const { accept } = useAuth();

    return <SignInScreen onDone={accept} />;
}
