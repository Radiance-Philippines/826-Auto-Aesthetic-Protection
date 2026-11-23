import { Platform } from 'react-native';

/** "Continue with Apple" exists on iOS only */
export const appleAvailable = () => Platform.OS === 'ios';

/** Apple's sheet; returns the identity token and, the first time only, the person's name. null: they backed out. */
export async function appleIdentity(): Promise<{ identityToken: string; fullName: string | null } | null> {
    const Apple = require('expo-apple-authentication') as typeof import('expo-apple-authentication');

    try {
        const credential = await Apple.signInAsync({ requestedScopes: [Apple.AppleAuthenticationScope.FULL_NAME, Apple.AppleAuthenticationScope.EMAIL] });

        if (!credential.identityToken) {
            return null;
        }

        const name = [credential.fullName?.givenName, credential.fullName?.familyName].filter(Boolean).join(' ');

        return { identityToken: credential.identityToken, fullName: name || null };
    } catch (error) {
        if ((error as { code?: string }).code === 'ERR_REQUEST_CANCELED') {
            return null;
        }

        throw error;
    }
}
