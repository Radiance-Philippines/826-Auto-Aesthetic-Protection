import type { LucideIcon } from 'lucide-react-native';
import { Archive, Banknote, Calculator, Columns3, LayoutGrid, Settings, Store, Users, Wallet } from 'lucide-react-native';
import { Linking } from 'react-native';
import type { AppTile } from '../api/types';

/** The server's neutral icon names (config/apps.php `glyph`) in this app's icon set; an unknown name still gets a tile */
const GLYPHS: Record<string, LucideIcon> = { store: Store, banknote: Banknote, columns: Columns3, settings: Settings, users: Users, wallet: Wallet, calculator: Calculator, archive: Archive };
export const glyphFor = (name: string): LucideIcon => GLYPHS[name] ?? LayoutGrid;

/** Each team app's own URL scheme (`expo.scheme` in apps/<app>/app.json): the way one installed app opens another */
export const APP_SCHEMES: Record<string, string> = { owner: 'aap826owner', sales: 'aap826sales', operations: 'aap826operations', system: 'aap826system' };

/** The drawer is only worth a button when there is a second tile (another app, or a planned one) */
export const worthShowing = (apps: AppTile[] | null): apps is AppTile[] => !!apps && apps.length > 1;

export type OpenResult = 'opened' | 'download' | 'none';

/**
 * Opens another team app: its URL scheme first (it is installed), else the download link the server
 * gave. 'none' means neither worked (not installed and no download published yet).
 */
export async function openApp(tile: AppTile): Promise<OpenResult> {
    const scheme = APP_SCHEMES[tile.key];

    if (scheme) {
        try {
            await Linking.openURL(`${scheme}://`);

            return 'opened';
        } catch {
            // not installed: fall through to the download
        }
    }

    if (tile.url) {
        try {
            await Linking.openURL(tile.url);

            return 'download';
        } catch {
            // no browser either
        }
    }

    return 'none';
}
