import { problemText, type ApiResult } from '@/api/client';

/** The server's password rule (App\Support\CustomerRules): 10 to 72 characters, a letter and a digit, not the email */
export const PASSWORD_RULE = 'At least 10 characters, with a letter and a digit.';

export const emailOk = (value: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());

export function passwordProblem(password: string, email = ''): string | null {
    if (!password) return 'Choose a password.';
    if (password.length < 10) return 'Use at least 10 characters.';
    if (password.length > 72) return 'Use 72 characters or fewer.';
    if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) return 'Add a letter and a digit.';
    if (email && password.toLowerCase() === email.trim().toLowerCase()) return 'Your password can’t be your email address.';

    return null;
}

/** What a screen shows for an answer from /app/auth/* */
export type AuthProblem = {
    /** A line above the button */
    form: string | null;
    /** Messages by field (register, change password) */
    fields: Record<string, string>;
    /** The address that still has to be verified: show "Resend" */
    resend: string | null;
    /** Email sign-up is switched off on the server: hide the form, say "Use Google" */
    unavailable: boolean;
};

export const SIGN_UP_UNAVAILABLE = 'Sign-up by email isn’t available yet. Use Google.';

const minutes = (seconds: number) => Math.max(1, Math.ceil(seconds / 60));

/**
 * 422 -> field messages (login: one sentence for the whole form, so nothing says which half was wrong),
 * 403 email_unverified -> resend, 429 locked -> how long, 503 email_sign_up_unavailable -> unavailable.
 */
export function authProblem(result: ApiResult<unknown>, typedEmail = '', { login = false } = {}): AuthProblem {
    const none: AuthProblem = { form: null, fields: {}, resend: null, unavailable: false };

    switch (result.kind) {
        case 'ok':
            return none;
        case 'invalid': {
            if (login) return { ...none, form: problemText(result) };

            const fields = Object.fromEntries(Object.entries(result.errors).map(([key, messages]) => [key, messages[0]]));

            return Object.keys(fields).length ? { ...none, fields } : { ...none, form: problemText(result) };
        }
        case 'blocked':
            return result.code === 'email_unverified' ? { ...none, form: result.message ?? 'Verify your email first.', resend: result.canResend ? (result.email ?? typedEmail) : null } : { ...none, form: problemText(result) };
        case 'tooMany':
            if (result.code === 'locked' && result.retryAfter) {
                const m = minutes(result.retryAfter);

                return { ...none, form: `Too many tries. Try again in about ${m} ${m === 1 ? 'minute' : 'minutes'}, or reset your password.` };
            }

            return { ...none, form: problemText(result) };
        case 'failed':
            return result.code === 'email_sign_up_unavailable' ? { ...none, form: SIGN_UP_UNAVAILABLE, unavailable: true } : { ...none, form: problemText(result) };
        default:
            return { ...none, form: problemText(result) };
    }
}
