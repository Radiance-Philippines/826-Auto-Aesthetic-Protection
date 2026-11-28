/** GET /console/api/{panel}/profile (ConsoleProfileController); every change answers with it again */
export type Profile = {
    name: string;
    email: string;
    /** Two-factor sign-in is required and not set up: only that can be done until it is */
    setupRequired: boolean;
    twoFactor: { app: boolean; recoveryCodes: number; email: boolean };
    passkeys: { allowed: boolean; rpId: string; userId: string; ids: string[]; items: { id: number; name: string; added: string; lastUsed: string | null }[] };
    events: { label: string; note: string | null; actor: string | null; at: string; ip: string | null }[];
};
