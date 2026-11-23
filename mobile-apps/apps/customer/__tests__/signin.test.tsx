import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import fs from 'fs';
import path from 'path';
import type { ComponentType } from 'react';
import { api, type Catalog } from '@/api';
import { CatalogProvider } from '@/context/catalog';
import { ThemeProvider, Button, Field } from '@826/core';
import ForgotPasswordScreen from '../app/forgot-password';
import RegisterScreen from '../app/register';
import SignInScreen from '../app/sign-in';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockRouter = { push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() };
const mockAccept = jest.fn();
let mockParams: Record<string, string> = {};
let mockGoogle = true;
let mockApple = true;

jest.mock('expo-router', () => ({ useRouter: () => mockRouter, useLocalSearchParams: () => mockParams }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));
jest.mock('@/lib/config', () => ({ API_URL: 'https://dran.dev/demo/826', DEMO: true, MOCK: true, GOOGLE_WEB_CLIENT_ID: 'x', GOOGLE_IOS_CLIENT_ID: '', IS_IOS: false }));
jest.mock('@/lib/storage', () => ({ getToken: async () => null, saveToken: async () => {}, clearToken: async () => {} }));
jest.mock('@/lib/google', () => ({ googleConfigured: () => mockGoogle, googleIdToken: jest.fn(async () => 'google-id-token') }));
jest.mock('@/lib/apple', () => ({ appleAvailable: () => mockApple, appleIdentity: jest.fn(async () => ({ identityToken: 'apple-token', fullName: null })) }));
jest.mock('@/context/auth', () => ({ deviceName: () => 'test', useAuth: () => ({ accept: mockAccept }) }));

let renderer: ReactTestRenderer | undefined;

const ON: Catalog['signIn'] = { google: true, apple: true, demo: true };
const guest = { id: 7, name: 'Maria Santos', email: 'maria@example.com', phone: null, avatar: null, referralCode: null, signInMethod: 'password' as const, emailVerified: true };

async function show(Screen: ComponentType, signIn: Catalog['signIn'] = ON) {
    const real = await api.catalog();
    if (real.kind !== 'ok') throw new Error('mock catalog');
    jest.spyOn(api, 'catalog').mockResolvedValue({ kind: 'ok', data: { ...real.data, signIn } });

    await act(async () => {
        renderer = create(
            <ThemeProvider scheme="dark">
                <CatalogProvider>
                    <Screen />
                </CatalogProvider>
            </ThemeProvider>,
        );
    });
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 5));
    });

    return renderer as ReactTestRenderer;
}

const words = (r: ReactTestRenderer) => JSON.stringify(r.toJSON(), (key, value) => (key === 'refreshControl' ? undefined : value));
const labels = (r: ReactTestRenderer) => r.root.findAllByType(Button).map((b) => b.props.label);
const field = (r: ReactTestRenderer, label: string) => r.root.findAll((node) => node.type === Field && node.props.label === label)[0];
const button = (r: ReactTestRenderer, testID: string) => r.root.findAllByType(Button).find((b) => b.props.testID === testID) as ReturnType<ReactTestRenderer['root']['findAllByType']>[number];
const type = (r: ReactTestRenderer, label: string, text: string) => act(() => field(r, label).props.onChangeText(text));
const press = (r: ReactTestRenderer, testID: string) =>
    act(async () => {
        await button(r, testID).props.onPress();
    });

beforeEach(() => {
    mockParams = {};
    mockGoogle = true;
    mockApple = true;
    mockAccept.mockReset();
    mockRouter.push.mockReset();
    mockRouter.replace.mockReset();
});

afterEach(() => {
    jest.useRealTimers();
    act(() => renderer?.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
});

describe('sign in', () => {
    it('is an email and password form with Sign in, Forgot password?, a create-account link and the "or" divider', async () => {
        const r = await show(SignInScreen);
        const tree = words(r);
        expect(tree).toContain('Book a drop-off date');
        expect(tree).toContain('follow your unit');
        expect(labels(r)).toEqual(['Sign in', 'Forgot password?', 'Sign in with Google', 'Sign in with Apple', 'Try the demo account', 'New here? Create an account']);
        expect(tree).toContain('"or"');
        expect(tree).not.toContain('Continue with');
    });

    it('asks the keyboard for the right thing: email and password autofill, return key flow, hidden password', async () => {
        const r = await show(SignInScreen);
        const email = field(r, 'Email').props;
        const password = field(r, 'Password').props;
        expect(email).toMatchObject({ autoComplete: 'email', keyboardType: 'email-address', autoCapitalize: 'none', returnKeyType: 'next' });
        expect(password).toMatchObject({ autoComplete: 'current-password', secureTextEntry: true, returnKeyType: 'go' });
    });

    it('has a show/hide icon button on the password that is 48dp and labelled', async () => {
        const r = await show(SignInScreen);
        const toggle = () => r.root.findAll((node) => node.props.testID === 'toggle-password' && node.props.accessibilityRole === 'button')[0];
        expect(toggle().props.accessibilityLabel).toBe('Show password');
        expect(toggle().props.style).toMatchObject({ width: 48, height: 48 });
        act(() => toggle().props.onPress());
        expect(toggle().props.accessibilityLabel).toBe('Hide password');
        expect(field(r, 'Password').props.secureTextEntry).toBe(false);
    });

    it('lists only the providers the server turned on (and this build can run)', async () => {
        expect(labels(await show(SignInScreen, { google: true, apple: false, demo: true }))).toEqual(['Sign in', 'Forgot password?', 'Sign in with Google', 'Try the demo account', 'New here? Create an account']);
        act(() => renderer?.unmount());
        expect(labels(await show(SignInScreen, { google: false, apple: true, demo: false }))).toEqual(['Sign in', 'Forgot password?', 'Sign in with Apple', 'New here? Create an account']);
        act(() => renderer?.unmount());
        const bare = await show(SignInScreen, { google: false, apple: false, demo: false });
        expect(labels(bare)).toEqual(['Sign in', 'Forgot password?', 'New here? Create an account']);
        expect(words(bare)).not.toContain('"or"');
    });

    it('hides Google without a client id in this build and Apple off iOS, even when the server has them on', async () => {
        mockGoogle = false;
        mockApple = false;
        expect(labels(await show(SignInScreen))).toEqual(['Sign in', 'Forgot password?', 'Try the demo account', 'New here? Create an account']);
    });

    it('shows a skeleton for the form while the catalog loads', async () => {
        const real = await api.catalog();
        if (real.kind !== 'ok') throw new Error('mock catalog');
        jest.spyOn(api, 'catalog').mockReturnValue(new Promise(() => {}));
        await act(async () => {
            renderer = create(
                <ThemeProvider scheme="dark">
                    <CatalogProvider>
                        <SignInScreen />
                    </CatalogProvider>
                </ThemeProvider>,
            );
        });
        expect(renderer?.root.findAllByProps({ testID: 'sign-in-loading' }).length).toBeGreaterThan(0);
        expect(labels(renderer as ReactTestRenderer)).toEqual([]);
    });

    it('signs in with the email (trimmed) and password, and keeps the token it gets', async () => {
        const login = jest.spyOn(api, 'login').mockResolvedValue({ kind: 'ok', data: { token: 'tok', guest } });
        const r = await show(SignInScreen);
        type(r, 'Email', '  maria@example.com ');
        type(r, 'Password', 'correct horse 42');
        await press(r, 'email-sign-in');
        expect(login).toHaveBeenCalledWith('maria@example.com', 'correct horse 42', 'test');
        expect(mockAccept).toHaveBeenCalledWith({ token: 'tok', guest });
    });

    it('the keyboard "go" key on the password signs in too', async () => {
        const login = jest.spyOn(api, 'login').mockResolvedValue({ kind: 'ok', data: { token: 'tok', guest } });
        const r = await show(SignInScreen);
        type(r, 'Email', 'maria@example.com');
        type(r, 'Password', 'correct horse 42');
        await act(async () => {
            await field(r, 'Password').props.onSubmitEditing();
        });
        expect(login).toHaveBeenCalledTimes(1);
    });

    it('asks for both before calling the server', async () => {
        const login = jest.spyOn(api, 'login');
        const r = await show(SignInScreen);
        await press(r, 'email-sign-in');
        expect(words(r)).toContain('Enter the email you signed up with.');
        type(r, 'Email', 'maria@example.com');
        await press(r, 'email-sign-in');
        expect(words(r)).toContain('Enter your password.');
        expect(login).not.toHaveBeenCalled();
    });

    it('422: one sentence that does not say which half was wrong', async () => {
        const text = 'Those details don’t match. Check your email and password.';
        jest.spyOn(api, 'login').mockResolvedValue({ kind: 'invalid', errors: { email: [text] }, message: text });
        const r = await show(SignInScreen);
        type(r, 'Email', 'maria@example.com');
        type(r, 'Password', 'wrong');
        await press(r, 'email-sign-in');
        expect(words(r)).toContain(text);
        expect(field(r, 'Email').props.error).toBeUndefined();
        expect(mockAccept).not.toHaveBeenCalled();
    });

    it('403 email_unverified: says so and offers Resend for that address', async () => {
        jest.spyOn(api, 'login').mockResolvedValue({ kind: 'blocked', code: 'email_unverified', message: 'Verify your email first. Open the link we sent you.', email: 'maria@example.com', canResend: true });
        const resend = jest.spyOn(api, 'resendVerification').mockResolvedValue({ kind: 'ok', data: {} });
        const r = await show(SignInScreen);
        type(r, 'Email', 'maria@example.com');
        type(r, 'Password', 'correct horse 42');
        await press(r, 'email-sign-in');
        expect(words(r)).toContain('Verify your email first.');
        expect(labels(r)).toContain('Send the email again');
        await press(r, 'resend');
        expect(resend).toHaveBeenCalledWith('maria@example.com');
        expect(words(r)).toContain('We sent a new link.');
        expect(labels(r)).toContain('Send again in 60 s');
    });

    it('429 locked: shows how long to wait', async () => {
        jest.spyOn(api, 'login').mockResolvedValue({ kind: 'tooMany', code: 'locked', retryAfter: 900, message: 'x' });
        const r = await show(SignInScreen);
        type(r, 'Email', 'maria@example.com');
        type(r, 'Password', 'whatever12');
        await press(r, 'email-sign-in');
        expect(words(r)).toContain('Try again in about 15 minutes, or reset your password.');
    });

    it('offline: says so', async () => {
        jest.spyOn(api, 'login').mockResolvedValue({ kind: 'offline' });
        const r = await show(SignInScreen);
        type(r, 'Email', 'maria@example.com');
        type(r, 'Password', 'whatever12');
        await press(r, 'email-sign-in');
        expect(words(r)).toContain('No connection.');
    });

    it('the mock server answers the sample addresses (unverified@ and locked@)', async () => {
        const r = await show(SignInScreen);
        type(r, 'Email', 'unverified@example.test');
        type(r, 'Password', 'correct horse 42');
        await press(r, 'email-sign-in');
        expect(labels(r)).toContain('Send the email again');
        type(r, 'Email', 'locked@example.test');
        await press(r, 'email-sign-in');
        expect(words(r)).toContain('about 15 minutes');
    });

    it('Create an account and Forgot password? open their screens; the email carries over from the sign-up screen', async () => {
        mockParams = { email: 'new@example.com' };
        const r = await show(SignInScreen);
        expect(field(r, 'Email').props.value).toBe('new@example.com');
        await press(r, 'create-account');
        expect(mockRouter.push).toHaveBeenCalledWith('/register');
        await press(r, 'forgot');
        expect(mockRouter.push).toHaveBeenCalledWith('/forgot-password');
    });

    it('Google and the demo account still sign in', async () => {
        const google = jest.spyOn(api, 'signInGoogle').mockResolvedValue({ kind: 'ok', data: { token: 'g', guest } });
        const demo = jest.spyOn(api, 'signInDemo').mockResolvedValue({ kind: 'ok', data: { token: 'd', guest } });
        const r = await show(SignInScreen);
        await press(r, 'google');
        expect(google).toHaveBeenCalledWith('google-id-token', 'test');
        expect(mockAccept).toHaveBeenLastCalledWith({ token: 'g', guest });
        await press(r, 'demo');
        expect(demo).toHaveBeenCalledWith('test');
        expect(mockAccept).toHaveBeenLastCalledWith({ token: 'd', guest });
    });

    it('points team members to the 826 Team app with no link', async () => {
        const r = await show(SignInScreen, { google: true, apple: false, demo: false });
        expect(words(r)).toContain('Are you on the team? Use the 826 Team app.');
        // no link: neither the line nor anything around it can be pressed
        const line = r.root.findAll((node) => node.props.children === 'Are you on the team? Use the 826 Team app.')[0];

        for (let node: typeof line | null = line; node; node = node.parent) expect(node.props.onPress).toBeUndefined();
    });
});

describe('create account', () => {
    const fill = (r: ReactTestRenderer, over: Partial<Record<'Name' | 'Email' | 'Password' | 'Mobile number', string>> = {}) => {
        const values = { Name: 'Maria Santos', Email: 'maria@example.com', Password: 'correct horse 42', 'Mobile number': '', ...over };
        for (const [label, value] of Object.entries(values)) type(r, label, value);
    };

    it('asks for name, email, password and an optional mobile number, and shows the password rules', async () => {
        const r = await show(RegisterScreen);
        const tree = words(r);
        expect(['Name', 'Email', 'Password', 'Mobile number'].map((label) => !!field(r, label))).toEqual([true, true, true, true]);
        expect(field(r, 'Mobile number').props.optional).toBe(true);
        expect(field(r, 'Password').props).toMatchObject({ autoComplete: 'new-password', secureTextEntry: true, hint: 'At least 10 characters, with a letter and a digit.' });
        expect(tree).toContain('At least 10 characters, with a letter and a digit.');
        expect(labels(r)).toEqual(['Create account', 'Create account with Google', 'Create account with Apple', 'Already have an account? Sign in']);
    });

    it('offers only the providers the catalog has on', async () => {
        expect(labels(await show(RegisterScreen, { google: false, apple: false, demo: true }))).toEqual(['Create account', 'Already have an account? Sign in']);
    });

    it('checks the fields before calling the server', async () => {
        const register = jest.spyOn(api, 'register');
        const r = await show(RegisterScreen);
        fill(r, { Name: 'M', Email: 'nope', Password: 'short1', 'Mobile number': '123' });
        await press(r, 'email-register');
        expect([field(r, 'Name').props.error, field(r, 'Email').props.error, field(r, 'Password').props.error, field(r, 'Mobile number').props.error]).toEqual(['Enter your name.', 'Enter a valid email address.', 'Use at least 10 characters.', 'Enter your mobile number, like 0917 123 4567.']);
        expect(register).not.toHaveBeenCalled();
    });

    it('202 verify_email: "Check your email", Resend with a 60 s wait, and "I verified, sign in" goes back with the address', async () => {
        const register = jest.spyOn(api, 'register').mockResolvedValue({ kind: 'ok', data: { status: 'verify_email', email: 'maria@example.com' } });
        const resend = jest.spyOn(api, 'resendVerification').mockResolvedValue({ kind: 'ok', data: {} });
        const r = await show(RegisterScreen);
        jest.useFakeTimers();
        fill(r, { 'Mobile number': '0917 123 4567' });
        await press(r, 'email-register');
        expect(register).toHaveBeenCalledWith({ name: 'Maria Santos', email: 'maria@example.com', password: 'correct horse 42', phone: '0917 123 4567' }, 'test');
        expect(mockAccept).not.toHaveBeenCalled();

        const tree = words(r);
        expect(tree).toContain('Check your email');
        expect(tree).toContain('We sent a link to maria@example.com.');
        expect(labels(r)).toEqual(['Send again in 60 s', 'I verified, sign in']);
        expect(button(r, 'resend').props.disabled).toBe(true);

        // one tick per second: each re-render sets the next timer
        for (let second = 0; second < 60; second++) {
            await act(async () => {
                jest.advanceTimersByTime(1000);
            });
        }
        expect(labels(r)[0]).toBe('Send the email again');
        await press(r, 'resend');
        expect(resend).toHaveBeenCalledWith('maria@example.com');
        expect(labels(r)[0]).toBe('Send again in 60 s');

        await press(r, 'verified');
        expect(mockRouter.replace).toHaveBeenCalledWith({ pathname: '/sign-in', params: { email: 'maria@example.com' } });
    });

    it('201 verified (the demo): signs straight in with the token', async () => {
        jest.spyOn(api, 'register').mockResolvedValue({ kind: 'ok', data: { status: 'verified', demo: true, token: 'tok', guest } });
        const r = await show(RegisterScreen);
        fill(r);
        await press(r, 'email-register');
        expect(mockAccept).toHaveBeenCalledWith({ token: 'tok', guest });
        expect(words(r)).not.toContain('Check your email');
    });

    it('works end to end against the mock server (no phone sent when left empty)', async () => {
        const r = await show(RegisterScreen);
        fill(r);
        await press(r, 'email-register');
        expect(mockAccept).toHaveBeenCalledWith(expect.objectContaining({ token: 'mock-token', guest: expect.objectContaining({ email: 'maria@example.com', signInMethod: 'password' }) }));
    });

    it('422: puts each message under its own field', async () => {
        jest.spyOn(api, 'register').mockResolvedValue({ kind: 'invalid', message: 'x', errors: { email: ['That email already has an account. Sign in instead.'], password: ['The password field must be at least 10 characters.'] } });
        const r = await show(RegisterScreen);
        fill(r);
        await press(r, 'email-register');
        expect(field(r, 'Email').props.error).toBe('That email already has an account. Sign in instead.');
        expect(field(r, 'Password').props.error).toContain('at least 10 characters');
    });

    it('503 email_sign_up_unavailable: hides the email form and says Use Google', async () => {
        jest.spyOn(api, 'register').mockResolvedValue({ kind: 'failed', message: 'Account sign-up by email isn’t available yet. Use Google.', code: 'email_sign_up_unavailable' });
        const r = await show(RegisterScreen);
        fill(r);
        await press(r, 'email-register');
        expect(words(r)).toContain('Use Google');
        expect(words(r)).toContain('Sign-up by email isn’t available yet. Use Google.');
        expect(field(r, 'Email')).toBeUndefined();
        expect(labels(r)).toEqual(['Create account with Google', 'Create account with Apple', 'Already have an account? Sign in']);
    });

    it('other failures keep the form and say what happened', async () => {
        jest.spyOn(api, 'register').mockResolvedValue({ kind: 'tooMany' });
        const r = await show(RegisterScreen);
        fill(r);
        await press(r, 'email-register');
        expect(words(r)).toContain('Too many tries in a row.');
        expect(field(r, 'Email')).toBeDefined();
    });

    it('Google creates the account too, and the link back goes to Sign in', async () => {
        const google = jest.spyOn(api, 'signInGoogle').mockResolvedValue({ kind: 'ok', data: { token: 'g', guest } });
        const r = await show(RegisterScreen);
        await press(r, 'google');
        expect(google).toHaveBeenCalled();
        expect(mockAccept).toHaveBeenCalledWith({ token: 'g', guest });
        await press(r, 'to-sign-in');
        expect(mockRouter.replace).toHaveBeenCalledWith('/sign-in');
    });
});

describe('forgot password', () => {
    it('asks for the email and sends the link', async () => {
        const forgot = jest.spyOn(api, 'forgotPassword').mockResolvedValue({ kind: 'ok', data: {} });
        const r = await show(ForgotPasswordScreen);
        expect(labels(r)).toEqual(['Send link']);
        await press(r, 'send-link');
        expect(words(r)).toContain('Enter a valid email address.');
        expect(forgot).not.toHaveBeenCalled();
        type(r, 'Email', ' maria@example.com ');
        await press(r, 'send-link');
        expect(forgot).toHaveBeenCalledWith('maria@example.com');
    });

    it('answers the same way for any address, and says the link opens in the browser', async () => {
        jest.spyOn(api, 'forgotPassword').mockResolvedValue({ kind: 'ok', data: {} });
        const r = await show(ForgotPasswordScreen);
        type(r, 'Email', 'maria@example.com');
        await press(r, 'send-link');
        const tree = words(r);
        expect(tree).toContain('Check your email');
        expect(tree).toContain('we emailed you a link. Open it, choose a new password, then come back and sign in.');
        expect(tree).toContain('The link opens in your browser.');
        expect(labels(r)).toEqual(['Back to sign in']);
        await press(r, 'back-to-sign-in');
        expect(mockRouter.replace).toHaveBeenCalledWith('/sign-in');
    });

    it('shows a server problem and keeps the form', async () => {
        jest.spyOn(api, 'forgotPassword').mockResolvedValue({ kind: 'tooMany' });
        const r = await show(ForgotPasswordScreen);
        type(r, 'Email', 'maria@example.com');
        await press(r, 'send-link');
        expect(words(r)).toContain('Too many tries in a row.');
        expect(labels(r)).toEqual(['Send link']);
    });
});

describe('the whole app', () => {
    it('lists the account screens for signed-out people in the router', () => {
        const layout = fs.readFileSync(path.join(__dirname, '../app/_layout.tsx'), 'utf8');
        const out = layout.slice(layout.indexOf('guard={!signedIn}'), layout.indexOf('guard={signedIn}'));
        for (const name of ['sign-in', 'register', 'forgot-password']) expect(out).toContain(`name="${name}"`);
    });
});
