import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'aap826.token';
let memory: string | null | undefined;

/** The sign-in token lives in the device's secure store (Keychain / Keystore) */
export async function getToken(): Promise<string | null> {
    if (memory !== undefined) {
        return memory;
    }

    try {
        memory = await SecureStore.getItemAsync(TOKEN_KEY);
    } catch {
        memory = null;
    }

    return memory;
}

export async function saveToken(token: string): Promise<void> {
    memory = token;

    try {
        await SecureStore.setItemAsync(TOKEN_KEY, token);
    } catch {
        // Kept in memory for this run only
    }
}

export async function clearToken(): Promise<void> {
    memory = null;

    try {
        await SecureStore.deleteItemAsync(TOKEN_KEY);
    } catch {
        // Nothing stored
    }
}
