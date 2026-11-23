import { copy } from './copy';

const text = copy.auth;

export const emailProblem = (email: string): string | null => {
    if (!email.trim()) {
        return text.emailRequired;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()) ? null : text.emailInvalid;
};

/** The phone is optional; typed, it needs 10 to 13 digits (the server's rule) */
export const optionalPhoneProblem = (phone: string): string | null => {
    const digits = phone.replace(/\D/g, '').length;

    return phone.trim() && (!/^\+?[\d\s().-]+$/.test(phone.trim()) || digits < 10 || digits > 13) ? text.phoneFormat : null;
};
