import { copy } from './copy';
import { phoneProblem } from './draft';

const text = copy.auth;

export const emailProblem = (email: string): string | null => {
    if (!email.trim()) {
        return text.emailRequired;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()) ? null : text.emailInvalid;
};

/** The phone is optional; typed, it must be a mobile number (the server's rule) */
export const optionalPhoneProblem = (phone: string): string | null => (phone.trim() && phoneProblem(phone) ? text.phoneFormat : null);
