import fs from 'fs';
import path from 'path';
import { act, create, type ReactTestRenderer } from 'react-test-renderer';
import { Linking } from 'react-native';
import { api, type AppTile } from '../src/api';
import { APP_SCHEMES, openApp } from '../src/lib/apps';
import { AppDrawer, AppsButton } from '../src/ui/AppDrawer';
import { mockUser } from '../src/api/mock';
import { byLabel, dump, press, renderAs } from '../test-utils/helpers';

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn(), back: jest.fn(), canGoBack: () => true, replace: jest.fn() }), useFocusEffect: (effect: () => void) => require('react').useEffect(effect, []) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));

const tiles: AppTile[] = [
    { key: 'owner', label: '826 Owner', icon: 'store', state: 'available', url: 'https://example.test/download/826-owner-0.1.0.apk' },
    { key: 'sales', label: '826 Sales', icon: 'banknote', state: 'current' },
    { key: 'system', label: '826 System', icon: 'settings', state: 'available' },
    { key: 'hr', label: 'HR', icon: 'users', state: 'soon' },
];

const render = async (element: React.ReactElement) => {
    let renderer!: ReactTestRenderer;
    await act(async () => {
        renderer = create(element);
    });

    return renderer;
};

const tile = (renderer: ReactTestRenderer, label: string) => renderer.root.findAll((n) => n.props.accessibilityLabel === label && n.props.accessibilityRole === 'button')[0];

afterEach(() => {
    jest.restoreAllMocks();
    (Linking.openURL as jest.Mock).mockReset();
});

describe('the app drawer', () => {
    it('shows a tile per app with its name, marks the current one and disables the soon ones', async () => {
        const text = dump(await render(<AppDrawer open onClose={jest.fn()} apps={tiles} />));

        for (const label of ['826 Owner', '826 Sales', '826 System', 'HR', 'Soon']) expect(text).toContain(label);
        const renderer = await render(<AppDrawer open onClose={jest.fn()} apps={tiles} />);
        expect(tile(renderer, '826 Sales, current app').props.accessibilityState).toEqual({ disabled: false, selected: true });
        expect(tile(renderer, 'HR, coming soon').props.accessibilityState).toEqual({ disabled: true, selected: false });
        expect(tile(renderer, 'HR, coming soon').props.disabled).toBe(true);
        expect(tile(renderer, '826 Owner').props.accessibilityState).toEqual({ disabled: false, selected: false });
    });

    it('opens the other app by its scheme and closes', async () => {
        const open = jest.spyOn(Linking, 'openURL').mockResolvedValue();
        const onClose = jest.fn();
        const renderer = await render(<AppDrawer open onClose={onClose} apps={tiles} />);
        await act(async () => {
            tile(renderer, '826 System').props.onPress();
        });

        expect(open).toHaveBeenCalledWith('aap826system://');
        expect(open).toHaveBeenCalledTimes(1);
        expect(onClose).toHaveBeenCalled();
    });

    it('falls back to the download link when the app is not installed', async () => {
        const open = jest.spyOn(Linking, 'openURL').mockRejectedValueOnce(new Error('no app')).mockResolvedValue();

        expect(await openApp(tiles[0])).toBe('download');
        expect(open.mock.calls.map(([url]) => url)).toEqual(['aap826owner://', 'https://example.test/download/826-owner-0.1.0.apk']);
    });

    it('says so when it is not installed and there is no download', async () => {
        jest.spyOn(Linking, 'openURL').mockRejectedValue(new Error('no app'));
        const onClose = jest.fn();
        const renderer = await render(<AppDrawer open onClose={onClose} apps={tiles} />);
        await act(async () => {
            tile(renderer, '826 System').props.onPress();
        });

        expect(dump(renderer)).toContain('826 System isn’t installed on this phone');
        expect(onClose).not.toHaveBeenCalled();
    });

    it('does nothing for the current app but close, and never opens a soon tile', async () => {
        const open = jest.spyOn(Linking, 'openURL').mockResolvedValue();
        const onClose = jest.fn();
        const renderer = await render(<AppDrawer open onClose={onClose} apps={tiles} />);
        await act(async () => {
            tile(renderer, '826 Sales, current app').props.onPress();
        });

        expect(onClose).toHaveBeenCalled();
        expect(open).not.toHaveBeenCalled();
        expect(await openApp(tiles[3])).toBe('none');
        expect(open).not.toHaveBeenCalled();
    });

    it('uses the scheme each app declares in its app.json', () => {
        for (const app of Object.keys(APP_SCHEMES)) {
            const json = JSON.parse(fs.readFileSync(path.join(__dirname, '../../../apps', app, 'app.json'), 'utf8'));
            expect(json.expo.scheme).toBe(APP_SCHEMES[app]);
        }
    });
});

describe('the header button', () => {
    it('is there for the owner (other apps and planned ones), labelled Apps, and opens the sheet', async () => {
        const renderer = await renderAs(<AppsButton user={mockUser('super_admin')} />, 'super_admin');

        expect(byLabel(renderer, 'Apps')).toHaveLength(1);
        expect(byLabel(renderer, 'Apps')[0].props.accessibilityRole).toBe('button');
        await press(renderer, 'Apps');
        const text = dump(renderer);
        expect(text).toContain('826 Owner');
        expect(text).toContain('Accounting');
        expect(tile(renderer, 'Inventory, coming soon')).toBeTruthy();
    });

    it('is not there for someone with one app and nothing planned', async () => {
        for (const role of ['sales_admin', 'operations', 'branch_manager'] as const) {
            const renderer = await renderAs(<AppsButton user={mockUser(role)} />, role);
            expect(byLabel(renderer, 'Apps')).toHaveLength(0);
        }
    });

    it('is not there when the call fails', async () => {
        jest.spyOn(api, 'apps').mockResolvedValue({ kind: 'offline' } as never);
        const renderer = await renderAs(<AppsButton user={mockUser('super_admin')} />, 'super_admin');

        expect(byLabel(renderer, 'Apps')).toHaveLength(0);
    });
});
