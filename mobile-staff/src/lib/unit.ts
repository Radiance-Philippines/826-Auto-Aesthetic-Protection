import type { Vehicle } from '@/api/types';

/** "2023 BMW 7 Series" */
export const unitName = (v: Pick<Vehicle, 'year' | 'model'>): string => [v.year, v.model].filter(Boolean).join(' ') || 'Unit';

/** "Sedan · NDA 4821" */
export const unitSub = (v: Pick<Vehicle, 'typeLabel' | 'plate'>): string => [v.typeLabel, v.plate].filter(Boolean).join(' · ');

export const initials = (name: string): string =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join('');
