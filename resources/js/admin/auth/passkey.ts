import { request } from '../lib/api';
import { boot } from '../lib/boot';

/*
 * The browser side of passkey sign-in, native WebAuthn only, as on the current
 * admin's page (resources/views/filament/passkeys/script.blade.php). The
 * server side is PasskeyLoginController.
 */

export const passkeysSupported = () =>
    window.isSecureContext && 'PublicKeyCredential' in window && typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function' && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function';

export async function conditionalSupported(): Promise<boolean> {
    try {
        return passkeysSupported() && (await PublicKeyCredential.getClientCapabilities()).conditionalGet === true;
    } catch {
        return false;
    }
}

/** Ask the device for a passkey and sign in with it; answers where to go next. "conditional" is the autofill list. */
export async function signInWithPasskey(remember: () => boolean, mediation?: 'conditional', signal?: AbortSignal): Promise<string> {
    const options = await request<PublicKeyCredentialRequestOptionsJSON>('GET', `${boot.base}/admin-passkeys/options`, undefined, signal);
    const credential = (await navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options), mediation, signal })) as PublicKeyCredential;
    const answer = await request<{ redirect: string }>('POST', `${boot.base}/admin-passkeys/login`, { credential: JSON.stringify(credential.toJSON()), remember: remember(), console: true });

    return answer.redirect;
}

/** Make a passkey on this device from the server's one-time options; answers what to send back */
export async function createPasskey(options: PublicKeyCredentialCreationOptionsJSON): Promise<string> {
    const credential = (await navigator.credentials.create({ publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options) })) as PublicKeyCredential;

    return JSON.stringify(credential.toJSON());
}

/** Keeps the password manager's list in step with the server (where the browser can) */
export async function signalPasskeys(rpId: string, userId: string, ids: string[]): Promise<void> {
    try {
        await window.PublicKeyCredential?.signalAllAcceptedCredentials?.({ rpId, userId, allAcceptedCredentialIds: ids });
    } catch (error) {
        console.warn('Passkey list not synced:', error);
    }
}

/** Plain words for what the browser throws; null when there is nothing to say */
export function explainPasskeyError(error: unknown): string | null {
    if (!(error instanceof DOMException)) {
        return error instanceof Error ? error.message : 'That passkey could not sign you in.';
    }

    switch (error.name) {
        case 'AbortError':
            return null;
        case 'NotAllowedError':
            return 'Cancelled or timed out. Try again when you are ready.';
        case 'InvalidStateError':
            return 'This device already has a passkey for your account.';
        case 'NotSupportedError':
            return 'This device cannot make a passkey that asks for your PIN or fingerprint.';
        case 'SecurityError':
            return 'Passkeys do not work on this address. They need https (or localhost).';
        default:
            return 'Something went wrong with the passkey. Try again.';
    }
}
