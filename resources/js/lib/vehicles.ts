import type { SavedVehicle } from '../types';
import { getJson } from './api';

/** The signed-in customer's saved units */
export const fetchMyVehicles = () => getJson<{ vehicles: SavedVehicle[] }>('/my-vehicles');

/** Model years a customer can pick: next year down to 1980 */
export function vehicleYears(): number[] {
    const top = new Date().getFullYear() + 1;

    return Array.from({ length: top - 1980 + 1 }, (_, index) => top - index);
}

/** "2021 Toyota Vios" */
export const unitName = (year: number | string | null | undefined, model: string | null | undefined) => [year, model].filter(Boolean).join(' ');
