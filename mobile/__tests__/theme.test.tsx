import { act, create } from 'react-test-renderer';
import { Text as RNText } from 'react-native';
import { ThemeProvider, useTheme } from '@/context/theme';
import { palettes, type Palette, type Scheme } from '@/theme';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

type Rgba = [number, number, number, number];

function parse(color: string): Rgba {
    const hex = /^#([0-9a-f]{6})$/i.exec(color);

    if (hex) {
        const n = parseInt(hex[1], 16);

        return [(n >> 16) & 255, (n >> 8) & 255, n & 255, 1];
    }

    const rgba = /^rgba\((\d+),(\d+),(\d+),([\d.]+)\)$/.exec(color);

    if (!rgba) throw new Error(`Unreadable colour ${color}`);

    return [Number(rgba[1]), Number(rgba[2]), Number(rgba[3]), Number(rgba[4])];
}

/** `top` laid over the opaque `under` */
const over = (top: string, under: string): Rgba => {
    const [r, g, b, a] = parse(top);
    const [ur, ug, ub] = parse(under);

    return [r * a + ur * (1 - a), g * a + ug * (1 - a), b * a + ub * (1 - a), 1];
};

const luminance = ([r, g, b]: Rgba) => {
    const lin = (v: number) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
};

const contrast = (a: Rgba, b: Rgba) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

const SCHEMES: Scheme[] = ['dark', 'light'];

describe('palettes', () => {
    it('have the same tokens in both schemes', () => {
        expect(Object.keys(palettes.light)).toEqual(Object.keys(palettes.dark));
        expect(Object.keys(palettes.light.tone)).toEqual(Object.keys(palettes.dark.tone));
    });

    it('keep the dark values the app always had, and the site light values', () => {
        expect(palettes.dark).toMatchObject({ bg: '#0B0C0E', surface: '#131518', accent: '#2AD3CB', text: '#EEF0F1' });
        expect(palettes.light).toMatchObject({ bg: '#ECEEEF', surface: '#FFFFFF', surface2: '#E0E4E5', text: '#0F1214', muted: '#4B5357', accent: '#0A6B69', onAccent: '#FFFFFF', link: '#0A6462', danger: '#B42318' });
    });

    it.each(SCHEMES)('%s: text, muted, accent, link, danger and gold read at WCAG AA (4.5:1) on the page, panels and tiles', (scheme) => {
        const c: Palette = palettes[scheme];

        for (const under of [c.bg, c.surface, c.surface2]) {
            for (const name of ['text', 'muted', 'accent', 'link', 'danger', 'gold'] as const) {
                expect({ scheme, name, under, ratio: contrast(parse(c[name]), parse(under)) >= 4.5 }).toEqual({ scheme, name, under, ratio: true });
            }
        }
    });

    it.each(SCHEMES)('%s: text on the accent button, on the gold panel and on the faint tints', (scheme) => {
        const c: Palette = palettes[scheme];

        expect(contrast(parse(c.onAccent), parse(c.accent))).toBeGreaterThanOrEqual(4.5);
        expect(contrast(parse(c.gold), parse(c.goldPanel))).toBeGreaterThanOrEqual(4.5);
        expect(contrast(parse(c.text), parse(c.goldPanel))).toBeGreaterThanOrEqual(4.5);
        // The chosen choice / ready panel: accent text and body text on the tint over the page
        expect(contrast(parse(c.accent), over(c.accentSoft, c.surface))).toBeGreaterThanOrEqual(4.5);
        expect(contrast(parse(c.text), over(c.accentSoft, c.bg))).toBeGreaterThanOrEqual(4.5);
        expect(contrast(parse(c.danger), over(c.dangerSoft, c.surface))).toBeGreaterThanOrEqual(4.5);
        // Field edges and the unchosen radio are non-text (3:1)
        expect(contrast(parse(c.field), parse(c.surface))).toBeGreaterThanOrEqual(3);
    });

    it.each(SCHEMES)('%s: every chip tone reads on its tint over a panel and over the page', (scheme) => {
        const c: Palette = palettes[scheme];

        for (const [name, tone] of Object.entries(c.tone)) {
            for (const under of [c.surface, c.bg]) {
                expect({ scheme, name, ok: contrast(parse(tone.fg), over(tone.bg, under)) >= 4.5 }).toEqual({ scheme, name, ok: true });
            }
        }
    });
});

describe('ThemeProvider', () => {
    function Probe() {
        return <RNText>{useTheme().scheme}</RNText>;
    }

    const shown = async (element: React.ReactElement) => {
        let renderer!: ReturnType<typeof create>;
        await act(async () => {
            renderer = create(element);
        });

        return renderer.root.findByType(RNText).props.children;
    };

    afterEach(() => jest.restoreAllMocks());

    it('follows the phone: light when the phone is light, dark when it is dark', async () => {
        const rn = require('react-native');
        const spy = jest.spyOn(rn, 'useColorScheme');

        spy.mockReturnValue('light');
        expect(await shown(<ThemeProvider><Probe /></ThemeProvider>)).toBe('light');
        spy.mockReturnValue('dark');
        expect(await shown(<ThemeProvider><Probe /></ThemeProvider>)).toBe('dark');
    });

    it('falls back to dark when the phone gives no setting, and to dark with no provider', async () => {
        const rn = require('react-native');
        jest.spyOn(rn, 'useColorScheme').mockReturnValue(null);
        expect(await shown(<ThemeProvider><Probe /></ThemeProvider>)).toBe('dark');
        expect(await shown(<Probe />)).toBe('dark');
    });
});
