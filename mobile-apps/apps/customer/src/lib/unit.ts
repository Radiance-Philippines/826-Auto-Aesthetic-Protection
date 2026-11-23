import type { CustomerBooking, SavedVehicle } from '@/api';

/** "2023 BMW 7 Series" */
export const unitName = (v: { year: number | null; model: string | null }): string => [v.year, v.model].filter(Boolean).join(' ') || 'Your unit';

const typeLabel = (type: string | null | undefined) => (type === 'suv' ? 'SUV' : type ? type[0].toUpperCase() + type.slice(1) : null);

/** "Sedan · NDA 4821" */
export const unitSub = (v: { type: string | null; typeLabel?: string | null; plate: string | null }): string => [v.typeLabel ?? typeLabel(v.type), v.plate].filter(Boolean).join(' · ');

export const bookingUnit = (b: CustomerBooking) => ({ name: unitName(b.vehicle), sub: unitSub(b.vehicle) });

export const savedUnit = (v: SavedVehicle) => ({ name: unitName(v), sub: unitSub({ type: v.type, plate: v.plate }) });

/** The services of a booking as one line: "Exterior detailing, Interior detailing" */
export const serviceLine = (b: Pick<CustomerBooking, 'items'>): string => b.items.map((item) => item.name).join(', ');
