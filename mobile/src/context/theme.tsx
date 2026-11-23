import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { useColorScheme } from 'react-native';
import { palettes, type Palette, type Scheme } from '@/theme';

const ThemeContext = createContext<Palette>(palettes.dark);

/** The phone's light or dark setting, as a palette. No setting known: dark, the brand's night bay. */
export function ThemeProvider({ children, scheme }: { children: ReactNode; /** Tests: skip the phone's setting */ scheme?: Scheme }) {
    const system = useColorScheme();
    const palette = palettes[scheme ?? (system === 'light' ? 'light' : 'dark')];
    const value = useMemo(() => palette, [palette]);

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

/** The colours of the current scheme: `const c = useTheme()` then `c.bg`, `c.text`, ... */
export const useTheme = (): Palette => useContext(ThemeContext);
