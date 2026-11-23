import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';
import { api, setUnauthorizedHandler, type AppGuest, type SignInResult } from '@/api';
import { clearToken, getToken, saveToken } from '@/lib/storage';

type Status = 'loading' | 'out' | 'in' | 'offline';

type Auth = {
    status: Status;
    guest: AppGuest | null;
    /** Keeps the token and the guest a sign-in returned */
    accept: (result: SignInResult) => Promise<void>;
    setGuest: (guest: AppGuest) => void;
    signOut: () => Promise<void>;
    /** After deleting the account: the token is already revoked on the server */
    forget: () => Promise<void>;
    retry: () => Promise<void>;
};

const AuthContext = createContext<Auth | null>(null);

export const deviceName = () => `826 app (${Platform.OS})`;

export function AuthProvider({ children }: { children: ReactNode }) {
    const [status, setStatus] = useState<Status>('loading');
    const [guest, setGuest] = useState<AppGuest | null>(null);

    const forget = useCallback(async () => {
        await clearToken();
        setGuest(null);
        setStatus('out');
    }, []);

    const restore = useCallback(async () => {
        if (!(await getToken())) {
            setStatus('out');

            return;
        }

        const result = await api.me();

        if (result.kind === 'ok') {
            setGuest(result.data);
            setStatus('in');
        } else if (result.kind === 'signIn' || result.kind === 'notFound') {
            await forget();
        } else {
            setStatus('offline');
        }
    }, [forget]);

    useEffect(() => {
        setUnauthorizedHandler(() => void forget());
        void restore();

        return () => setUnauthorizedHandler(undefined);
    }, [forget, restore]);

    const value = useMemo<Auth>(
        () => ({
            status,
            guest,
            accept: async ({ token, guest: signedIn }) => {
                await saveToken(token);
                setGuest(signedIn);
                setStatus('in');
            },
            setGuest,
            signOut: async () => {
                // The server revokes the token; the phone forgets it either way
                await api.signOut();
                await forget();
            },
            forget,
            retry: restore,
        }),
        [status, guest, forget, restore],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): Auth {
    const auth = useContext(AuthContext);

    if (!auth) {
        throw new Error('useAuth needs an AuthProvider');
    }

    return auth;
}
