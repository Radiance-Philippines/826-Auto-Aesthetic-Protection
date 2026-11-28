/** What console.blade.php hands the React admin */
type Console = {
    /** The folder the app is served from ("" or "/demo/826") */
    base: string;
    panel: string;
    logo: string | null;
    /** Only on the sign-in pages (auth/main.tsx) */
    auth?: AuthPage;
};

export type TwoFactorStep = {
    methods: ('app' | 'email')[];
    /** Whether an emailed code just went out; null when none was tried */
    sent: boolean | null;
};

export type AuthPage =
    | { screen: 'sign-in'; passkeys: boolean; status: string | null; twoFactor: TwoFactorStep | null }
    | { screen: 'forgot' }
    | { screen: 'reset'; email: string; token: string };

declare global {
    interface Window {
        ADMIN826: Console;
    }
}

export const boot = window.ADMIN826;

/** The router's basename: screens are "/queue", "/vehicle-sizes" */
export const routerBase = `${boot.base}/console/${boot.panel}`;

export const apiBase = `${boot.base}/console/api/${boot.panel}`;

export const csrfToken = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
