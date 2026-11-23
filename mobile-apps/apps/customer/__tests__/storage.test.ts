jest.mock('expo-secure-store', () => {
    const store = new Map<string, string>();

    return {
        getItemAsync: jest.fn(async (key: string) => store.get(key) ?? null),
        setItemAsync: jest.fn(async (key: string, value: string) => void store.set(key, value)),
        deleteItemAsync: jest.fn(async (key: string) => void store.delete(key)),
    };
});

import * as SecureStore from 'expo-secure-store';
import { clearToken, getToken, saveToken } from '@/lib/storage';

describe('token storage', () => {
    it('saves into the secure store and reads it back', async () => {
        expect(await getToken()).toBeNull();
        await saveToken('abc');
        expect(SecureStore.setItemAsync).toHaveBeenCalledWith('aap826.token', 'abc');
        expect(await getToken()).toBe('abc');
    });

    it('forgets the token', async () => {
        await saveToken('abc');
        await clearToken();
        expect(SecureStore.deleteItemAsync).toHaveBeenCalledWith('aap826.token');
        expect(await getToken()).toBeNull();
    });
});
