import * as SecureStore from 'expo-secure-store';

/**
 * The sign-in token in the device's secure store (Keychain / Keystore), kept in memory too.
 * Each app gives its own key, so a signed-in session is never shared between two apps.
 */
export function createTokenStore(key: string) {
    let memory: string | null | undefined;

    return {
        async getToken(): Promise<string | null> {
            if (memory !== undefined) {
                return memory;
            }

            try {
                memory = await SecureStore.getItemAsync(key);
            } catch {
                memory = null;
            }

            return memory;
        },
        async saveToken(token: string): Promise<void> {
            memory = token;

            try {
                await SecureStore.setItemAsync(key, token);
            } catch {
                // Kept in memory for this run only
            }
        },
        async clearToken(): Promise<void> {
            memory = null;

            try {
                await SecureStore.deleteItemAsync(key);
            } catch {
                // Nothing stored
            }
        },
    };
}
