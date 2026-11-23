// Tokens copied from ../resources/css/app.css (the dark "night bay"). Do not add colours here without adding them there.
// The app is dark only, like the design in design/renders/Customer-app.
export const c = {
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
    // Chip tones (design system DS3): text on a faint tint of itself
    tone: {
        neutral: { fg: '#B4BBBF', bg: 'rgba(238,240,241,0.07)' },
        blue: { fg: '#8AB4FF', bg: 'rgba(138,180,255,0.14)' },
        teal: { fg: '#2AD3CB', bg: 'rgba(42,211,203,0.14)' },
        gold: { fg: '#E0C56E', bg: 'rgba(224,197,110,0.14)' },
        green: { fg: '#7BE0A0', bg: 'rgba(123,224,160,0.14)' },
        red: { fg: '#FF9E92', bg: 'rgba(255,158,146,0.14)' },
    },
} as const;

export type Tone = keyof typeof c.tone;

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
