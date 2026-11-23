import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import type { ComponentType } from 'react';
import fs from 'fs';
import path from 'path';
import { Plus } from 'lucide-react-native';
import ts from 'typescript';
import { CatalogProvider } from '@/context/catalog';
import { ThemeProvider } from '@/context/theme';
import { palettes, type Scheme } from '@/theme';
import { Button } from '@/ui/Button';
import { setReducedMotion } from '@/ui/motion';
import { EmptyState, ErrorState } from '@/ui/States';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let mockParams: Record<string, string> = { id: '1' };

jest.mock('expo-router', () => ({
    useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }),
    useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []),
    useLocalSearchParams: () => mockParams,
}));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));
jest.mock('@/lib/config', () => ({ API_URL: 'https://dran.dev/demo/826', DEMO: true, MOCK: true, GOOGLE_WEB_CLIENT_ID: 'x', GOOGLE_IOS_CLIENT_ID: '', IS_IOS: false }));
jest.mock('@/lib/google', () => ({ googleConfigured: () => true, googleIdToken: jest.fn() }));
jest.mock('@/lib/storage', () => ({ getToken: async () => 'mock-token', saveToken: async () => {}, clearToken: async () => {} }));
jest.mock('@/lib/apple', () => ({ appleAvailable: () => true, appleIdentity: jest.fn() }));
jest.mock('@/context/auth', () => ({
    deviceName: () => '826 app (test)',
    useAuth: () => ({ status: 'in', guest: { id: 1, name: 'Miguel Reyes', email: 'miguel@example.com', phone: '0917 000 0000' }, accept: jest.fn(), setGuest: jest.fn(), signOut: jest.fn(), forget: jest.fn(), retry: jest.fn() }),
}));

/* eslint-disable @typescript-eslint/no-require-imports */
const screens: Record<string, { load: () => { default: ComponentType }; params?: Record<string, string> }> = {
    Home: { load: () => require('../app/(tabs)/index') },
    Book: { load: () => require('../app/(tabs)/book') },
    Garage: { load: () => require('../app/(tabs)/garage') },
    Rewards: { load: () => require('../app/(tabs)/rewards') },
    Me: { load: () => require('../app/(tabs)/me') },
    'Sign in': { load: () => require('../app/sign-in') },
    Bookings: { load: () => require('../app/bookings') },
    'Booking in progress': { load: () => require('../app/booking/[id]'), params: { id: '1' } },
    'Booking finished': { load: () => require('../app/booking/[id]'), params: { id: '2' } },
    'Booking awaiting payment': { load: () => require('../app/booking/[id]'), params: { id: '3' } },
    Vehicle: { load: () => require('../app/vehicle/[id]'), params: { id: '1' } },
    Review: { load: () => require('../app/review/[id]'), params: { id: '2' } },
    Warranty: { load: () => require('../app/warranty') },
    'Home service': { load: () => require('../app/home-service') },
    'Our work': { load: () => require('../app/work') },
};

let renderer: ReactTestRenderer | undefined;

// A count-up mid-way would make two renders differ: the phone's "remove animations" setting shows the end state
beforeAll(() => setReducedMotion(true));

async function show(name: string, scheme: Scheme): Promise<ReactTestRenderer> {
    const entry = screens[name];
    mockParams = entry.params ?? { id: '1' };
    const Screen = entry.load().default;

    await act(async () => {
        renderer = create(
            <ThemeProvider scheme={scheme}>
                <CatalogProvider>
                    <Screen />
                </CatalogProvider>
            </ThemeProvider>,
        );
    });

    // The fake API answers on the next tick; let the screens settle
    for (let i = 0; i < 4; i++) {
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 5));
        });
    }

    return renderer as ReactTestRenderer;
}

// Screen's ScrollView holds a RefreshControl element that points back at its own fiber
const json = (r: ReactTestRenderer) => JSON.stringify(r.toJSON(), (key, value) => (key === 'refreshControl' ? undefined : value));
const texts = (r: ReactTestRenderer) => (json(r).match(/"children":\["([^"]+)"\]/g) ?? []).join(' ');

afterEach(() => {
    act(() => renderer?.unmount());
    renderer = undefined;
});

const SCHEMES: Scheme[] = ['dark', 'light'];

describe('key screens in both schemes', () => {
    it.each(SCHEMES)('%s: Home, Rewards, Sign in and a booking take their colours from the scheme', async (scheme) => {
        const mine = palettes[scheme];
        const other = palettes[scheme === 'dark' ? 'light' : 'dark'];

        for (const name of ['Home', 'Rewards', 'Sign in', 'Booking in progress', 'Garage', 'Me']) {
            const tree = json(await show(name, scheme));
            expect({ name, page: tree.includes(`"backgroundColor":"${mine.bg}"`) }).toEqual({ name, page: true });
            expect({ name, wrongPage: tree.includes(`"backgroundColor":"${other.bg}"`) }).toEqual({ name, wrongPage: false });
            expect({ name, text: tree.includes(mine.text) }).toEqual({ name, text: true });
            await act(async () => renderer?.unmount());
        }
    });

    it('draw the same words in light and dark', async () => {
        for (const name of ['Home', 'Rewards', 'Sign in', 'Booking in progress']) {
            const dark = texts(await show(name, 'dark'));
            await act(async () => renderer?.unmount());
            const light = texts(await show(name, 'light'));
            await act(async () => renderer?.unmount());
            expect({ name, same: light === dark, filled: dark.length > 40 }).toEqual({ name, same: true, filled: true });
        }
    });

    it('chips and the rewards card follow the scheme too', async () => {
        const rewards = json(await show('Rewards', 'light'));
        expect(rewards).toContain(palettes.light.goldPanel);
        expect(rewards).not.toContain(palettes.dark.goldPanel);
        await act(async () => renderer?.unmount());
        const home = json(await show('Home', 'light'));
        expect(home).toContain(palettes.light.tone.teal.bg);
        expect(home).not.toContain(palettes.dark.tone.teal.bg);
    });
});

describe('every button has a leading icon', () => {
    it.each(Object.keys(screens))('%s', async (name) => {
        const r = await show(name, 'dark');
        const buttons = r.root.findAllByType(Button);

        // The screen loaded its data (not just a skeleton), so its buttons are really under test
        if (!['Bookings', 'Our work', 'Warranty'].includes(name)) expect(buttons.length).toBeGreaterThan(0);

        for (const button of buttons) {
            // the Button was given an icon, and it drew it (an svg) as the first thing in its row
            expect({ label: button.props.label, icon: !!button.props.icon }).toEqual({ label: button.props.label, icon: true });
            expect({ label: button.props.label, drawn: button.findAll((node) => (node.type as unknown) === 'RNSVGSvgView').length > 0 }).toEqual({ label: button.props.label, drawn: true });
        }
    });

    it('also in the shared error and empty states', async () => {
        const r = await show('Home', 'dark');
        expect(r.root.findAllByType(Button).length).toBeGreaterThan(0);
        await act(async () => renderer?.unmount());
        await act(async () => {
            renderer = create(<ThemeProvider scheme="dark"><ErrorState result={{ kind: 'offline' }} onRetry={() => {}} /><EmptyState title="x" action={{ label: 'Go', icon: Plus, onPress: () => {} }} /></ThemeProvider>);
        });
        const all = (renderer as ReactTestRenderer).root.findAllByType(Button);
        expect(all.length).toBe(2);
        expect(all.every((b) => !!b.props.icon)).toBe(true);
    });

    it('no <Button> anywhere in the source is missing its icon prop', () => {
        const missing: string[] = [];
        const walk = (dir: string): string[] => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : e.name.endsWith('.tsx') ? [path.join(dir, e.name)] : []));

        for (const file of [...walk(path.join(__dirname, '../app')), ...walk(path.join(__dirname, '../src'))]) {
            const source = ts.createSourceFile(file, fs.readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
            const visit = (node: ts.Node) => {
                if ((ts.isJsxSelfClosingElement(node) || ts.isJsxOpeningElement(node)) && node.tagName.getText() === 'Button') {
                    if (!node.attributes.properties.some((p) => ts.isJsxAttribute(p) && p.name.getText() === 'icon')) missing.push(`${path.relative(path.join(__dirname, '..'), file)}:${source.getLineAndCharacterOfPosition(node.getStart()).line + 1}`);
                }
                ts.forEachChild(node, visit);
            };
            visit(source);
        }

        expect(missing).toEqual([]);
    });
});
