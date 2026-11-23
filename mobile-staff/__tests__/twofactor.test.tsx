import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { SignInScreen } from '@/features/SignIn';
import { setMockRole } from '@/api/mock';
import { byLabel, dump, press, settle, type as typeInto } from '../test-utils/helpers';

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));

async function open(onDone = jest.fn()) {
    let renderer!: ReactTestRenderer;
    await act(async () => {
        renderer = create(<SignInScreen onDone={onDone} />);
    });

    return { renderer, onDone };
}

describe('sign in and the two-step check', () => {
    it('signs in straight away when the account has no second step', async () => {
        const { renderer, onDone } = await open();
        await typeInto(renderer, 'Email', 'ops@example.test');
        await typeInto(renderer, 'Password', 'sample-password');
        await press(renderer, 'Sign in');
        expect(onDone).toHaveBeenCalledWith(expect.objectContaining({ token: 'mock-token' }));
    });

    it('shows a plain message for wrong details and does not sign in', async () => {
        const { renderer, onDone } = await open();
        await typeInto(renderer, 'Email', 'ops@example.test');
        await typeInto(renderer, 'Password', 'nope');
        await press(renderer, 'Sign in');
        expect(onDone).not.toHaveBeenCalled();
        expect(dump(renderer)).toContain('Those details don’t match. Check your email and password.');
    });

    it('asks for the 6-digit code, then signs in with it', async () => {
        const { renderer, onDone } = await open();
        await typeInto(renderer, 'Email', 'owner@example.test');
        await typeInto(renderer, 'Password', 'sample-password');
        await press(renderer, 'Sign in');
        expect(onDone).not.toHaveBeenCalled();
        expect(dump(renderer)).toContain('Two-step check');

        await typeInto(renderer, '6-digit code', '12ab34');
        // letters are dropped, so only 4 digits: Verify stays off
        expect(byLabel(renderer, 'Verify')[0].props.accessibilityState.disabled).toBe(true);
        await typeInto(renderer, '6-digit code', '123456');
        await press(renderer, 'Verify');
        expect(onDone).toHaveBeenCalledWith(expect.objectContaining({ token: 'mock-token' }));
    });

    it('keeps the person on the code step after a wrong code', async () => {
        const { renderer, onDone } = await open();
        await typeInto(renderer, 'Email', 'owner@example.test');
        await typeInto(renderer, 'Password', 'sample-password');
        await press(renderer, 'Sign in');
        await typeInto(renderer, '6-digit code', '000000');
        await press(renderer, 'Verify');
        expect(onDone).not.toHaveBeenCalled();
        expect(dump(renderer)).toContain('That code isn’t right. Check the app and try again.');
    });

    it('goes back to email and password when the server stops the challenge (too many wrong codes)', async () => {
        const { renderer } = await open();
        await typeInto(renderer, 'Email', 'owner@example.test');
        await typeInto(renderer, 'Password', 'sample-password');
        await press(renderer, 'Sign in');
        const { api } = require('@/api');
        const original = api.twoFactor;
        api.twoFactor = async () => ({ kind: 'tooMany', message: 'Too many wrong codes. Enter your email and password again.' });
        await typeInto(renderer, '6-digit code', '000000');
        await press(renderer, 'Verify');
        api.twoFactor = original;
        expect(dump(renderer)).toContain('Too many wrong codes. Enter your email and password again.');
        expect(dump(renderer)).toContain('Sign in with the account you use on the 826 admin.');
    });

    it('shows the website-setup message when two-step is required but not set up', async () => {
        const { renderer, onDone } = await open();
        const { api } = require('@/api');
        const original = api.signIn;
        api.signIn = async () => ({ kind: 'forbidden', message: 'Two-factor sign-in is required for the team. On the website, open your profile and set up an authenticator app first, then sign in here again.' });
        await typeInto(renderer, 'Email', 'a@example.test');
        await typeInto(renderer, 'Password', 'x');
        await press(renderer, 'Sign in');
        api.signIn = original;
        expect(onDone).not.toHaveBeenCalled();
        expect(dump(renderer)).toContain('set up an authenticator app first');
    });

    it('switches to a backup code and back', async () => {
        const { renderer } = await open();
        await typeInto(renderer, 'Email', 'owner@example.test');
        await typeInto(renderer, 'Password', 'sample-password');
        await press(renderer, 'Sign in');
        await press(renderer, 'Use a backup code');
        expect(dump(renderer)).toContain('Backup code');
        await press(renderer, 'Use my authenticator app');
        expect(dump(renderer)).toContain('6-digit code');
        await settle();
        setMockRole('operations');
    });
});
