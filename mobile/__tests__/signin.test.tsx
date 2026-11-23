import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import fs from 'fs';
import path from 'path';
import { api, type Catalog } from '@/api';
import { CatalogProvider } from '@/context/catalog';
import { ThemeProvider } from '@/context/theme';
import { Button } from '@/ui/Button';
import SignInScreen from '../app/sign-in';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));
jest.mock('@/lib/config', () => ({ API_URL: 'https://dran.dev/demo/826', DEMO: true, MOCK: true, GOOGLE_WEB_CLIENT_ID: 'x', GOOGLE_IOS_CLIENT_ID: '', IS_IOS: false }));
jest.mock('@/lib/storage', () => ({ getToken: async () => null, saveToken: async () => {}, clearToken: async () => {} }));
jest.mock('@/lib/google', () => ({ googleConfigured: () => true, googleIdToken: jest.fn() }));
jest.mock('@/lib/apple', () => ({ appleAvailable: () => true, appleIdentity: jest.fn() }));
jest.mock('@/context/auth', () => ({ deviceName: () => 'test', useAuth: () => ({ accept: jest.fn() }) }));

let renderer: ReactTestRenderer | undefined;

async function show(signIn: Catalog['signIn']) {
    const real = await api.catalog();
    if (real.kind !== 'ok') throw new Error('mock catalog');
    jest.spyOn(api, 'catalog').mockResolvedValue({ kind: 'ok', data: { ...real.data, signIn } });

    await act(async () => {
        renderer = create(
            <ThemeProvider scheme="dark">
                <CatalogProvider>
                    <SignInScreen />
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

afterEach(() => {
    act(() => renderer?.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
});

const SELF_SIGN_UP = /sign[\s-]?up|create (an |your |my )?account|\bregister\b|\bget started\b|\bjoin (us|now|today)\b|new (here|customer)/i;

describe('sign in', () => {
    it('is "Sign in" only: no sign-up, create-account, register, join or get-started wording or button', async () => {
        const tree = words(await show({ google: true, apple: true, demo: true }));
        expect(tree).toContain('Sign in');
        expect(tree).not.toMatch(SELF_SIGN_UP);
        expect(tree).not.toContain('Continue with');
    });

    it('is for customers: says what the app does and lists the providers the server turned on', async () => {
        const r = await show({ google: true, apple: false, demo: true });
        const tree = words(r);
        expect(tree).toContain('Book a drop-off date');
        expect(tree).toContain('follow your unit');
        expect(r.root.findAllByType(Button).map((b) => b.props.label)).toEqual(['Sign in with Google', 'Try the demo account']);
    });

    it('shows an Apple button when Apple is on, and no demo account when the server turns it off', async () => {
        const r = await show({ google: false, apple: true, demo: false });
        expect(r.root.findAllByType(Button).map((b) => b.props.label)).toEqual(['Sign in with Apple']);
    });

    it('points team members to the 826 Team app with no link', async () => {
        const r = await show({ google: true, apple: false, demo: false });
        expect(words(r)).toContain('Are you on the team? Use the 826 Team app.');
        // no link: neither the line nor anything around it can be pressed
        const line = r.root.findAll((node) => node.props.children === 'Are you on the team? Use the 826 Team app.')[0];

        for (let node: typeof line | null = line; node; node = node.parent) expect(node.props.onPress).toBeUndefined();
    });
});

describe('the whole app', () => {
    it('has no self sign-up wording or link anywhere in its source', () => {
        const hits: string[] = [];
        const walk = (dir: string): string[] => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : /\.(tsx?|json)$/.test(e.name) ? [path.join(dir, e.name)] : []));

        for (const file of [...walk(path.join(__dirname, '../app')), ...walk(path.join(__dirname, '../src')), path.join(__dirname, '../app.json')]) {
            fs.readFileSync(file, 'utf8')
                .split('\n')
                .forEach((line, index) => {
                    if (SELF_SIGN_UP.test(line)) hits.push(`${path.relative(path.join(__dirname, '..'), file)}:${index + 1}: ${line.trim()}`);
                });
        }

        expect(hits).toEqual([]);
    });
});
