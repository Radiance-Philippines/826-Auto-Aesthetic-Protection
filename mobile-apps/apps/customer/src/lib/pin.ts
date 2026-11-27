import { getPin, savePin } from '@/lib/storage';

/** While 826 is in testing the server asks for the team PIN (a 423): every call sends the one entered */
let pin: Promise<string | null> | null = null;
let onLocked: (() => void) | undefined;

export const setLockedHandler = (handler: (() => void) | undefined) => {
    onLocked = handler;
};

export async function setPin(code: string) {
    pin = Promise.resolve(code);
    await savePin(code);
}

export const pinnedFetch: typeof fetch = async (input, init) => {
    pin ??= getPin();
    const code = await pin;
    const response = await fetch(input, code ? { ...init, headers: { ...(init?.headers as Record<string, string> | undefined), 'X-Site-Pin': code } } : init);

    if (response.status === 423) onLocked?.();

    return response;
};
