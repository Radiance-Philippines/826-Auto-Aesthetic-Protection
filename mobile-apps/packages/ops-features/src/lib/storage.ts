import { createTokenStore } from '@826/core';

/** The sign-in token lives in the device's secure store (Keychain / Keystore). Same key as the old 826 Team app, so Operations keeps the session on update. */
export const { getToken, saveToken, clearToken } = createTokenStore('aap826staff.token');
