import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';
import { api, setUnauthorizedHandler, type SignedIn, type StaffUser } from '@/api';
import { clearToken, getToken, saveToken } from '@/lib/storage';

type Status = 'loading' | 'out' | 'in' | 'offline';

export type Auth = {
    status: Status;
    user: StaffUser | null;
    /** Keeps the token and the user a finished sign-in returned */
    accept: (result: SignedIn) => Promise<void>;
    refresh: () => Promise<void>;
    signOut: () => Promise<void>;
    retry: () => Promise<void>;
};

export const AuthContext = createContext<Auth | null>(null);

export const deviceName = () => `826 Team (${Platform.OS})`;

export function AuthProvider({ children }: { children: ReactNode }) {
    const [status, setStatus] = useState<Status>('loading');
    const [user, setUser] = useState<StaffUser | null>(null);

    const forget = useCallback(async () => {
        await clearToken();
        setUser(null);
        setStatus('out');
    }, []);

    const restore = useCallback(async () => {
        if (!(await getToken())) {
            setStatus('out');

            return;
        }

        const result = await api.me();

        if (result.kind === 'ok') {
            setUser(result.data);
            setStatus('in');
        } else if (result.kind === 'signIn' || result.kind === 'notFound' || result.kind === 'forbidden') {
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
            user,
            accept: async ({ token, user: signedIn }) => {
                await saveToken(token);
                setUser(signedIn);
                setStatus('in');
            },
            refresh: async () => {
                const result = await api.me();
                if (result.kind === 'ok') setUser(result.data);
            },
            signOut: async () => {
                // The server revokes the token; the phone forgets it either way
                await api.signOut();
                await forget();
            },
            retry: restore,
        }),
        [status, user, forget, restore],
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

/** The signed-in person; screens behind the sign-in guard always have one */
export function useUser(): StaffUser {
    const { user } = useAuth();

    if (!user) {
        throw new Error('useUser needs a signed-in person');
    }

    return user;
}
