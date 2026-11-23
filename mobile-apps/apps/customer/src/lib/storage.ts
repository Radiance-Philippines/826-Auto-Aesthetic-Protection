import { createTokenStore } from '@826/core';

/** The sign-in token lives in the device's secure store (Keychain / Keystore) */
export const { getToken, saveToken, clearToken } = createTokenStore('aap826.token');
