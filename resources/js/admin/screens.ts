import type { ComponentType, LazyExoticComponent } from 'react';

import { lazyPage } from '../lib/lazyPage';

/**
 * One file per ported screen: screens/<filament-slug>.tsx, default export.
 * Adding the file is all it takes: the route, and the sidebar link that stops
 * going to Filament. Each ships as its own chunk.
 */
export const screens: Record<string, LazyExoticComponent<ComponentType>> = Object.fromEntries(
    Object.entries(import.meta.glob<{ default: ComponentType }>('./screens/*.tsx')).map(([file, load]) => [file.slice('./screens/'.length, -'.tsx'.length), lazyPage(load)]),
);
