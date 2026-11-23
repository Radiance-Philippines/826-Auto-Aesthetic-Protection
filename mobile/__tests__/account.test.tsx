import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { api } from '@/api';
import { sortResponse } from '@/api/client';
import { ThemeProvider } from '@/context/theme';
import { authProblem, passwordProblem } from '@/lib/account';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import MeScreen from '../app/(tabs)/me';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let mockGuest: Record<string, unknown> = {};
const mockSetGuest = jest.fn();

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: () => {} }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));
jest.mock('@/lib/config', () => ({ API_URL: 'https://dran.dev/demo/826', DEMO: true, MOCK: true, GOOGLE_WEB_CLIENT_ID: 'x', GOOGLE_IOS_CLIENT_ID: '', IS_IOS: false }));
jest.mock('@/lib/storage', () => ({ getToken: async () => 'mock-token', saveToken: async () => {}, clearToken: async () => {} }));
jest.mock('@/context/catalog', () => ({ useCatalog: () => ({ catalog: null, error: null, reload: async () => {} }) }));
jest.mock('@/context/auth', () => ({ useAuth: () => ({ guest: mockGuest, setGuest: mockSetGuest, signOut: jest.fn(), forget: jest.fn() }) }));

describe('server answers, sorted', () => {
    it('keeps the code of a 403, and the lock time of a 429', () => {
        expect(sortResponse(403, { message: 'm', code: 'email_unverified', canResend: true, email: 'a@b.co' })).toEqual({ kind: 'blocked', code: 'email_unverified', message: 'm', email: 'a@b.co', canResend: true });
        expect(sortResponse(429, { message: 'm', code: 'locked', retryAfter: 900 })).toEqual({ kind: 'tooMany', code: 'locked', message: 'm', retryAfter: 900 });
        expect(sortResponse(503, { message: 'm', code: 'email_sign_up_unavailable' })).toEqual({ kind: 'failed', message: 'm', code: 'email_sign_up_unavailable' });
    });

    it('a 403 or 429 without a code behaves as before', () => {
        expect(sortResponse(403, null).kind).toBe('notFound');
        expect(sortResponse(429, { message: 'Too Many Attempts.' })).toMatchObject({ kind: 'tooMany', code: null, retryAfter: null });
    });
});

describe('authProblem', () => {
    it('login 422: one sentence for the form', () => {
        const text = 'Those details don’t match. Check your email and password.';
        expect(authProblem({ kind: 'invalid', errors: { email: [text] }, message: text }, '', { login: true })).toEqual({ form: text, fields: {}, resend: null, unavailable: false });
    });

    it('register 422: messages by field', () => {
        expect(authProblem({ kind: 'invalid', errors: { email: ['a'], password: ['b', 'c'] }, message: null }).fields).toEqual({ email: 'a', password: 'b' });
    });

    it('403 email_unverified: resend for the address the server names, else the typed one, never when it cannot', () => {
        const blocked = { kind: 'blocked' as const, code: 'email_unverified', message: 'Verify first.', canResend: true };
        expect(authProblem({ ...blocked, email: 's@x.co' }, 't@x.co').resend).toBe('s@x.co');
        expect(authProblem({ ...blocked, email: null }, 't@x.co').resend).toBe('t@x.co');
        expect(authProblem({ ...blocked, email: 's@x.co', canResend: false }).resend).toBeNull();
    });

    it('429 locked says the minutes; a plain 429 stays generic; 503 means unavailable', () => {
        expect(authProblem({ kind: 'tooMany', code: 'locked', retryAfter: 61 }).form).toContain('about 2 minutes');
        expect(authProblem({ kind: 'tooMany', code: 'locked', retryAfter: 30 }).form).toContain('about 1 minute,');
        expect(authProblem({ kind: 'tooMany' }).form).toContain('Too many tries in a row');
        expect(authProblem({ kind: 'failed', message: null, code: 'email_sign_up_unavailable' })).toMatchObject({ unavailable: true, form: expect.stringContaining('Use Google') });
        expect(authProblem({ kind: 'failed', message: null }).unavailable).toBe(false);
    });
});

describe('the password rule', () => {
    it.each([
        ['', 'Choose a password.'],
        ['abc123', 'Use at least 10 characters.'],
        ['abcdefghijkl', 'Add a letter and a digit.'],
        ['1234567890123', 'Add a letter and a digit.'],
        ['a1'.repeat(37), 'Use 72 characters or fewer.'],
    ])('%j -> %s', (password, message) => expect(passwordProblem(password)).toBe(message));

    it('accepts ten characters with a letter and a digit, but not the email address', () => {
        expect(passwordProblem('correct horse 42')).toBeNull();
        expect(passwordProblem('abcdefgh12')).toBeNull();
        expect(passwordProblem('maria12345@x.co', 'Maria12345@X.co')).toBe('Your password can’t be your email address.');
    });
});

describe('Me', () => {
    let renderer: ReactTestRenderer | undefined;
    const show = async () => {
        await act(async () => {
            renderer = create(
                <ThemeProvider scheme="dark">
                    <MeScreen />
                </ThemeProvider>,
            );
        });

        return renderer as ReactTestRenderer;
    };
    const words = (r: ReactTestRenderer) => JSON.stringify(r.toJSON(), (key, value) => (key === 'refreshControl' ? undefined : value));
    const field = (r: ReactTestRenderer, label: string) => r.root.findAll((node) => node.type === Field && node.props.label === label)[0];
    const type = (r: ReactTestRenderer, label: string, text: string) => act(() => field(r, label).props.onChangeText(text));
    const change = (r: ReactTestRenderer) =>
        act(async () => {
            await r.root.findAllByType(Button).find((b) => b.props.testID === 'change-password-button')?.props.onPress();
        });
    const base = { id: 7, name: 'Maria Santos', email: 'maria@example.com', phone: null, avatar: null, referralCode: null };

    beforeEach(() => mockSetGuest.mockReset());
    afterEach(() => {
        act(() => renderer?.unmount());
        renderer = undefined;
        jest.restoreAllMocks();
    });

    it('says how the customer signs in', async () => {
        mockGuest = { ...base, signInMethod: 'password' };
        expect(words(await show())).toContain('Signed in with email');
        act(() => renderer?.unmount());
        mockGuest = { ...base, signInMethod: 'google' };
        expect(words(await show())).toContain('Signed in with Google');
        act(() => renderer?.unmount());
        mockGuest = { ...base, signInMethod: 'apple' };
        expect(words(await show())).toContain('Signed in with Apple');
        act(() => renderer?.unmount());
        mockGuest = base;
        expect(words(await show())).not.toContain('Signed in with');
    });

    it('Google and Apple customers have no password form', async () => {
        for (const signInMethod of ['google', 'apple', null]) {
            mockGuest = { ...base, signInMethod };
            const r = await show();
            expect(r.root.findAllByProps({ testID: 'change-password' })).toHaveLength(0);
            expect(words(r)).not.toContain('Change password');
            act(() => renderer?.unmount());
        }
    });

    it('a password customer changes it with current_password and password only', async () => {
        mockGuest = { ...base, signInMethod: 'password' };
        const update = jest.spyOn(api, 'updateMe').mockResolvedValue({ kind: 'ok', data: { ...base, signInMethod: 'password' } as never });
        const r = await show();
        expect(field(r, 'Current password').props).toMatchObject({ autoComplete: 'current-password', secureTextEntry: true });
        expect(field(r, 'New password').props).toMatchObject({ autoComplete: 'new-password', secureTextEntry: true, hint: 'At least 10 characters, with a letter and a digit.' });

        await change(r);
        expect(field(r, 'Current password').props.error).toBe('Enter your current password.');
        expect(field(r, 'New password').props.error).toBe('Choose a password.');
        expect(update).not.toHaveBeenCalled();

        type(r, 'Current password', 'correct horse 42');
        type(r, 'New password', 'a brand new pass 5');
        await change(r);
        expect(update).toHaveBeenCalledWith({ current_password: 'correct horse 42', password: 'a brand new pass 5' });
        expect(words(r)).toContain('Password changed. Your other devices are signed out.');
        expect(field(r, 'New password').props.value).toBe('');
        expect(mockSetGuest).toHaveBeenCalled();
    });

    it('a wrong current password shows under that field (mock server)', async () => {
        mockGuest = { ...base, signInMethod: 'password' };
        const r = await show();
        type(r, 'Current password', 'wrong');
        type(r, 'New password', 'a brand new pass 5');
        await change(r);
        expect(field(r, 'Current password').props.error).toBe('That isn’t your current password.');
        expect(words(r)).not.toContain('Password changed.');
    });
});
