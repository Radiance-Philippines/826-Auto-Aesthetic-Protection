import { useSyncExternalStore } from 'react';

/** Filament's own key and values, so a choice made there or here holds in both */
const KEY = 'filament:theme:mode';

export type ThemeChoice = 'light' | 'dark' | 'system';

const listeners = new Set<() => void>();
const device = window.matchMedia('(prefers-color-scheme: dark)');

function read(): ThemeChoice {
    try {
        const value = localStorage.getItem(KEY);

        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
}

let current = read();

function apply(): void {
    document.documentElement.classList.toggle('dark', current === 'dark' || (current === 'system' && device.matches));
}

device.addEventListener('change', apply);
window.addEventListener('storage', (event) => {
    if (event.key === KEY) {
        current = read();
        apply();
        listeners.forEach((listener) => listener());
    }
});

export function setTheme(choice: ThemeChoice): void {
    current = choice;

    try {
        localStorage.setItem(KEY, choice);
    } catch {
        // No storage: it still switches for this visit
    }

    apply();
    listeners.forEach((listener) => listener());
}

export function useTheme(): ThemeChoice {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => current,
    );
}
