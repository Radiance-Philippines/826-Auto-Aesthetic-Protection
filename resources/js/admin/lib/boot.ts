/** What console.blade.php hands the React admin */
type Console = {
    /** The folder the app is served from ("" or "/demo/826") */
    base: string;
    panel: string;
    logo: string | null;
};

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
