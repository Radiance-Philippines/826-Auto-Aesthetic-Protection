import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { SignInScreen } from '../src/features/SignIn';
import { SystemScreen } from '../src/features/System';
import { getTeamApp, wrongAppText } from '../src/lib/teamApp';
import { visibleTabs } from '../src/lib/tabs';
import { api } from '../src/api';
import { saveToken } from '../src/lib/storage';
import { dump, press, renderAs, type as typeInto } from '../test-utils/helpers';

jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '0.1.0', extra: { team: { appKey: 'sales', label: '826 Sales', accent: '#2AD1CB' } } } } }));
jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));

async function signInAs(email: string) {
    const onDone = jest.fn();
    let renderer!: ReactTestRenderer;
    await act(async () => {
        renderer = create(<SignInScreen onDone={onDone} />);
    });
    await typeInto(renderer, 'Email', email);
    await typeInto(renderer, 'Password', 'sample-password');
    await press(renderer, 'Sign in');

    return { renderer, onDone };
}

describe('the app this bundle runs as', () => {
    it('reads its key, label and colour from the app config', () => {
        expect(getTeamApp()).toEqual({ appKey: 'sales', label: '826 Sales', accent: '#2AD1CB' });
    });

    it('titles the sign-in screen with its own name', async () => {
        const { renderer } = await signInAs('');
        expect(dump(renderer)).toContain('826 Sales');
    });

    it('sends X-Team-App on every staff call and gets that app’s tabs', async () => {
        await saveToken('mock-token');
        const me = await api.me();
        expect(me.kind).toBe('ok');
        expect(me.kind === 'ok' && me.data.app).toBe('sales');
    });

    it('signs in when the account fits the app', async () => {
        const { onDone } = await signInAs('sales@example.test');
        expect(onDone).toHaveBeenCalledWith(expect.objectContaining({ user: expect.objectContaining({ app: 'sales', tabs: ['today', 'clients', 'inbox', 'me'] }) }));
    });

    it('shows the refusal plainly when the account is for another app, and signs nobody in', async () => {
        const { renderer, onDone } = await signInAs('ops@example.test');
        expect(onDone).not.toHaveBeenCalled();
        expect(dump(renderer)).toContain('This app is for 826 Sales. Use 826 Operations.');
    });

    it('words the refusal itself when the server sent no sentence', () => {
        expect(wrongAppText('operations')).toBe('This app is for 826 Sales. Use 826 Operations.');
        expect(wrongAppText(null)).toBe('This app is for 826 Sales.');
    });
});

describe('tabs follow the server', () => {
    it('the system app has its one tab and Me', () => expect(visibleTabs(['system', 'me'])).toEqual(['system', 'me']));
    it('an unknown tab from a newer server is dropped, not shown', () => expect(visibleTabs(['payroll', 'today'])).toEqual(['today', 'me']));
});

describe('System screen', () => {
    it('is refused for an app that is not System (the server says 403)', async () => {
        await saveToken('mock-token');
        const result = await api.system();
        expect(result.kind).toBe('forbidden');
    });

    it('lists what is on, the app versions and the security switches', async () => {
        jest.resetModules();
        jest.doMock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { extra: { team: { appKey: 'system', label: '826 System', accent: '#8B7CF6' } } } } }));
        const { SystemScreen: Screen } = require('../src/features/System') as { SystemScreen: typeof SystemScreen };
        const { renderAs: render } = require('../test-utils/helpers') as { renderAs: typeof renderAs };
        const renderer = await render(<Screen />, 'super_admin');
        const text = dump(renderer);
        expect(text).toContain('Connections');
        expect(text).toContain('Google sign-in');
        expect(text).toContain('826 Operations');
        expect(text).toContain('Two-step check required');
        expect(text).not.toMatch(/password|secret|token/i);
    });
});
