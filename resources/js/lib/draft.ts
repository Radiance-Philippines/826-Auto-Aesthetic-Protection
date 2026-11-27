/**
 * What a customer has filled in, kept in this tab (sessionStorage) so a
 * reload or the trip to Google's sign-in and back doesn't lose it.
 */
export function loadDraft<T extends object>(key: string, empty: T): T {
    try {
        const saved = window.sessionStorage.getItem(key);

        if (!saved) {
            return empty;
        }

        const parsed = JSON.parse(saved) as Partial<T>;

        // Only the fields this version knows, each of the type it expects
        return Object.fromEntries(
            Object.entries(empty).map(([field, fallback]) => {
                const value = (parsed as Record<string, unknown>)[field];
                const sameType = value !== undefined && (fallback === null || value === null || typeof value === typeof fallback) && Array.isArray(value) === Array.isArray(fallback);

                return [field, sameType ? value : fallback];
            }),
        ) as T;
    } catch {
        return empty;
    }
}

export function saveDraft<T extends object>(key: string, draft: T): void {
    try {
        window.sessionStorage.setItem(key, JSON.stringify(draft));
    } catch {
        // Private browsing without storage: it just isn't kept
    }
}

export function clearDraft(key: string): void {
    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // Nothing to clear
    }
}

/** A Philippine mobile number as the server takes it: 11 digits starting 09 or 08, or +63 and the last ten */
export function phoneProblem(value: string): 'missing' | 'format' | null {
    const trimmed = value.trim();

    if (!trimmed) {
        return 'missing';
    }

    return /^\+?[\d\s().-]+$/.test(trimmed) && /^(0|\+?63)[89]\d{9}$/.test(trimmed.replace(/[\s().-]/g, '')) ? null : 'format';
}

/** What the phone box keeps of what was typed or pasted: no letters, and no digit past a full number (11, or 12 with 63) */
export function limitPhone(value: string): string {
    const clean = value.replace(/[^\d\s+().-]/g, '');
    const longest = /^\s*\+?63/.test(clean) ? 12 : 11;
    let digits = 0;
    let kept = '';

    for (const char of clean) {
        if (/\d/.test(char) && ++digits > longest) {
            break;
        }

        kept += char;
    }

    return kept;
}

export const emailLooksWrong = (value: string) => value.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());

export const plateLooksWrong = (value: string) => value.trim() !== '' && (!/^[A-Za-z0-9 -]+$/.test(value.trim()) || value.trim().length > 15);

/** The reservation wizard's draft (Book), also filled in by "Book again" */
export const BOOK_DRAFT = 'app826-book-draft';

export type BookDraft = {
    branchId: number | null;
    /** A saved unit's id; null for a new unit typed in below */
    vehicleId: number | null;
    year: string;
    model: string;
    type: string;
    plate: string;
    serviceIds: number[];
    /** The service whose link started this: only branches offering it are shown while it stays picked */
    onlyService?: number | null;
    date: string | null;
    name: string;
    phone: string;
    email: string;
    message: string;
    /** A friend's referral code (optional; only when the owner has turned referral on) */
    referral: string;
    consent: boolean;
};

export const emptyBookDraft = (): BookDraft => ({
    branchId: null,
    vehicleId: null,
    year: '',
    model: '',
    type: '',
    plate: '',
    serviceIds: [],
    date: null,
    name: '',
    phone: '',
    email: '',
    message: '',
    referral: '',
    consent: false,
});
