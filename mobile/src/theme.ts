// Tokens copied from ../resources/css/app.css (dark "night bay" and the light "bay by day"). Do not add colours here without adding them there.
// Read them with useTheme() (src/context/theme.tsx): the palette follows the phone's light or dark setting.
export type Scheme = 'dark' | 'light';

export type Palette = {
    scheme: Scheme;
    bg: string;
    surface: string;
    surface2: string;
    text: string;
    muted: string;
    line: string;
    field: string;
    accent: string;
    onAccent: string;
    link: string;
    gold: string;
    danger: string;
    scrim: string;
    steel: string;
    /** The edge of a secondary button */
    outline: string;
    /** Faint tints for picked and warning panels */
    accentSoft: string;
    accentLine: string;
    dangerSoft: string;
    dangerLine: string;
    /** The rewards code card */
    goldPanel: string;
    goldLine: string;
    /** The shimmer band on a loading block */
    shimmer: string;
    /** The unit drawing: body, cut-outs (glass, wheel hubs) and ground shadow */
    car: { body: string; cut: string; shadow: string };
    /** The opening animation: tyre smoke colour (white-grey on dark, soft grey with more opacity on light) and the car's ground shadow */
    intro: { smoke: string; smokeGain: number; ground: string };
    /** Chip tones (design system DS3): text on a faint tint of itself */
    tone: Record<'neutral' | 'blue' | 'teal' | 'gold' | 'green' | 'red', { fg: string; bg: string }>;
};

// Dark: the "night bay" from resources/css/app.css (:root[data-theme='dark']).
const dark: Palette = {
    scheme: 'dark',
    bg: '#0B0C0E',
    surface: '#131518',
    surface2: '#1D2024',
    text: '#EEF0F1',
    muted: '#9CA4A8',
    line: 'rgba(238,240,241,0.09)',
    field: '#6B7478',
    accent: '#2AD3CB',
    onAccent: '#032120',
    link: '#52DFD8',
    gold: '#CDB57A',
    danger: '#FF9E92',
    scrim: 'rgba(0,0,0,0.66)',
    steel: '#8D969A',
    outline: 'rgba(238,240,241,0.18)',
    accentSoft: 'rgba(42,211,203,0.12)',
    accentLine: 'rgba(42,211,203,0.3)',
    dangerSoft: 'rgba(255,158,146,0.12)',
    dangerLine: 'rgba(255,158,146,0.18)',
    goldPanel: '#1B1A16',
    goldLine: 'rgba(205,181,122,0.25)',
    shimmer: 'rgba(238,240,241,0.07)',
    car: { body: '#9AA3A7', cut: '#1D2024', shadow: 'rgba(0,0,0,0.5)' },
    intro: { smoke: '#E9ECEE', smokeGain: 0.55, ground: '#000000' },
    tone: {
        neutral: { fg: '#B4BBBF', bg: 'rgba(238,240,241,0.07)' },
        blue: { fg: '#8AB4FF', bg: 'rgba(138,180,255,0.14)' },
        teal: { fg: '#2AD3CB', bg: 'rgba(42,211,203,0.14)' },
        gold: { fg: '#E0C56E', bg: 'rgba(224,197,110,0.14)' },
        green: { fg: '#7BE0A0', bg: 'rgba(123,224,160,0.14)' },
        red: { fg: '#FF9E92', bg: 'rgba(255,158,146,0.14)' },
    },
};

// Light: the same bay by day (resources/css/app.css :root). The site keeps gold as a hairline, never text; the app
// shows gold as text (points, hold timer), so the light gold is a darker one that still passes AA.
const light: Palette = {
    scheme: 'light',
    bg: '#ECEEEF',
    surface: '#FFFFFF',
    surface2: '#E0E4E5',
    text: '#0F1214',
    muted: '#4B5357',
    line: 'rgba(15,18,20,0.12)',
    field: '#7A8286',
    accent: '#0A6B69',
    onAccent: '#FFFFFF',
    link: '#0A6462',
    gold: '#7A5C0F',
    danger: '#B42318',
    scrim: 'rgba(10,12,14,0.5)',
    steel: '#8D969A',
    outline: 'rgba(15,18,20,0.22)',
    accentSoft: 'rgba(10,107,105,0.1)',
    accentLine: 'rgba(10,107,105,0.35)',
    dangerSoft: 'rgba(180,35,24,0.08)',
    dangerLine: 'rgba(180,35,24,0.2)',
    goldPanel: '#FBF6E8',
    goldLine: 'rgba(184,154,78,0.45)',
    shimmer: 'rgba(15,18,20,0.06)',
    car: { body: '#6B7478', cut: '#E0E4E5', shadow: 'rgba(22,30,34,0.25)' },
    intro: { smoke: '#7D858B', smokeGain: 0.8, ground: '#0F1214' },
    tone: {
        neutral: { fg: '#4B5357', bg: 'rgba(15,18,20,0.07)' },
        blue: { fg: '#1D4ED8', bg: 'rgba(29,78,216,0.1)' },
        teal: { fg: '#0A6B69', bg: 'rgba(10,107,105,0.1)' },
        gold: { fg: '#7A5C0F', bg: 'rgba(184,154,78,0.18)' },
        green: { fg: '#15692E', bg: 'rgba(21,105,46,0.1)' },
        red: { fg: '#B42318', bg: 'rgba(180,35,24,0.1)' },
    },
};

export const palettes: Record<Scheme, Palette> = { dark, light };

export type Tone = keyof Palette['tone'];

export const radius = { sm: 8, md: 12, lg: 16, xl: 20, pill: 999 } as const;
export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 } as const;
/** Tap targets in dp */
export const target = 48;

// Archivo Expanded ExtraBold Italic is the design's heading face; only the standard-width cut ships with Expo, so headings add letter spacing.
export const fonts = {
    regular: 'Archivo_400Regular',
    medium: 'Archivo_500Medium',
    semi: 'Archivo_600SemiBold',
    bold: 'Archivo_700Bold',
    display: 'Archivo_800ExtraBold_Italic',
} as const;
