import { createTokenStore } from '@826/core';

/** The sign-in token lives in the device's secure store (Keychain / Keystore) */
export const { getToken, saveToken, clearToken } = createTokenStore('aap826.token');

/** The team's testing PIN, kept the same way (see lib/pin.ts) */
export const { getToken: getPin, saveToken: savePin } = createTokenStore('aap826.pin');
