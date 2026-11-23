import type { Branch, BranchHours, HomeRequestInput, HomeWindow, MonthAvailability, ReservationInput, Service, UnitInput } from '@/api/types';
import { addDays, isoDate, parseDate } from './dates';

export const STEPS = ['branch', 'unit', 'services', 'date', 'details', 'review'] as const;
export type Step = (typeof STEPS)[number];

export const STEP_TITLES: Record<Step, string> = {
    branch: 'Your branch',
    unit: 'Your unit',
    services: 'Services',
    date: 'Drop-off day',
    details: 'Your details',
    review: 'Check and reserve',
};

export type UnitDraft = { mode: 'saved' | 'new'; vehicleId: number | null; year: string; model: string; type: string; plate: string };

export type Draft = {
    branchId: number | null;
    unit: UnitDraft;
    serviceIds: number[];
    date: string | null;
    name: string;
    phone: string;
    email: string;
    message: string;
    consent: boolean;
    referral: string;
};

export const emptyUnit = (): UnitDraft => ({ mode: 'new', vehicleId: null, year: '', model: '', type: '', plate: '' });

export const emptyDraft = (): Draft => ({ branchId: null, unit: emptyUnit(), serviceIds: [], date: null, name: '', phone: '', email: '', message: '', consent: false, referral: '' });

export type Errors = Record<string, string>;

export const phoneDigits = (value: string) => value.replace(/\D/g, '');

/** The server's rule: 10 to 13 digits, digits and + ( ) . - and spaces only */
export const phoneOk = (value: string) => /^\+?[\d\s().-]+$/.test(value.trim()) && phoneDigits(value).length >= 10 && phoneDigits(value).length <= 13;

export const plateOk = (value: string) => /^[A-Za-z0-9 -]+$/.test(value.trim()) && value.trim().length <= 15;

const emailOk = (value: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());

export function validateUnit(unit: UnitDraft, maxYear = new Date().getFullYear() + 1): Errors {
    if (unit.mode === 'saved') {
        return unit.vehicleId ? {} : { vehicle: 'Pick a unit, or add a new one.' };
    }

    const errors: Errors = {};
    const year = Number(unit.year);

    if (!/^\d{4}$/.test(unit.year) || year < 1950 || year > maxYear) errors.year = 'Add your unit’s year, like 2021.';
    if (!unit.model.trim()) errors.model = 'Add your unit’s make and model, like Toyota Vios.';
    if (!unit.type) errors.type = 'Pick your unit’s type.';
    if (!unit.plate.trim()) errors.plate = 'Add your plate number.';
    else if (!plateOk(unit.plate)) errors.plate = 'Use letters and numbers only for the plate.';

    return errors;
}

export function validateContact(draft: Pick<Draft, 'name' | 'phone' | 'email' | 'consent'>): Errors {
    const errors: Errors = {};

    if (!draft.name.trim()) errors.name = 'Add your name so the branch knows who to look for.';
    if (!draft.phone.trim()) errors.phone = 'Add a mobile number so the branch can reach you.';
    else if (!phoneOk(draft.phone)) errors.phone = 'Enter your mobile number, like 0917 123 4567.';
    if (draft.email.trim() && !emailOk(draft.email)) errors.email = 'That doesn’t look like an email address. Check it, or leave it blank.';
    if (!draft.consent) errors.consent = 'Agree to the privacy notice to continue.';

    return errors;
}

export function validateStep(step: Step, draft: Draft): Errors {
    switch (step) {
        case 'branch':
            return draft.branchId ? {} : { branch: 'Pick a branch.' };
        case 'unit':
            return validateUnit(draft.unit);
        case 'services':
            return draft.serviceIds.length ? {} : { services: 'Pick at least one service.' };
        case 'date':
            return draft.date ? {} : { date: 'Pick a day on the calendar.' };
        case 'details':
            return validateContact(draft);
        default:
            return {};
    }
}

/** The unit part of a request: a saved unit's id, or the typed one */
export function unitInput(unit: UnitDraft): UnitInput {
    return unit.mode === 'saved' && unit.vehicleId
        ? { vehicle_id: unit.vehicleId }
        : { vehicle_year: Number(unit.year), vehicle_model: unit.model.trim(), vehicle_type: unit.type, plate_number: unit.plate.trim() };
}

export function reservationInput(draft: Draft): ReservationInput {
    return {
        branch: draft.branchId as number,
        services: [...draft.serviceIds].sort((a, b) => a - b),
        date: draft.date as string,
        ...unitInput(draft.unit),
        name: draft.name.trim(),
        phone: draft.phone.trim(),
        ...(draft.email.trim() ? { email: draft.email.trim() } : {}),
        ...(draft.message.trim() ? { message: draft.message.trim() } : {}),
        ...(draft.referral.trim() ? { referral_code: draft.referral.trim() } : {}),
        consent: draft.consent,
    };
}

export type HomeDraft = {
    branchId: number | null;
    unit: UnitDraft;
    serviceIds: number[];
    date: string | null;
    window: HomeWindow | null;
    address: string;
    area: string;
    areaOther: string;
    landmark: string;
    water: boolean | null;
    power: boolean | null;
    shade: boolean | null;
    name: string;
    phone: string;
    email: string;
    message: string;
    consent: boolean;
};

export const emptyHomeDraft = (): HomeDraft => ({ branchId: null, unit: emptyUnit(), serviceIds: [], date: null, window: null, address: '', area: '', areaOther: '', landmark: '', water: null, power: null, shade: null, name: '', phone: '', email: '', message: '', consent: false });

export function validateHome(draft: HomeDraft, branch: Branch | undefined): Errors {
    const errors: Errors = { ...(draft.branchId ? {} : { branch: 'Pick a branch.' }), ...validateUnit(draft.unit), ...validateContact(draft) };

    if (!draft.serviceIds.length) errors.services = 'Pick at least one service.';
    if (!draft.date) errors.date = 'Pick the day you’d like us to come.';
    if (!draft.window) errors.window = 'Pick morning, afternoon or any time.';
    if (!draft.address.trim()) errors.address = 'Add the address where the unit will be.';

    if (branch && branch.homeService.areas.length) {
        if (!draft.area) errors.area = 'Pick your area.';
        else if (draft.area === 'Other' && !draft.areaOther.trim()) errors.areaOther = 'Tell us your area.';
    }

    return errors;
}

export function homeInput(draft: HomeDraft): HomeRequestInput {
    return {
        branch: draft.branchId as number,
        services: [...draft.serviceIds].sort((a, b) => a - b),
        preferred_date: draft.date as string,
        preferred_window: draft.window as HomeWindow,
        address: draft.address.trim(),
        ...(draft.area ? { area: draft.area } : {}),
        ...(draft.area === 'Other' ? { area_other: draft.areaOther.trim() } : {}),
        ...(draft.landmark.trim() ? { landmark: draft.landmark.trim() } : {}),
        ...(draft.water !== null ? { has_water: draft.water } : {}),
        ...(draft.power !== null ? { has_power: draft.power } : {}),
        ...(draft.shade !== null ? { has_shade: draft.shade } : {}),
        ...unitInput(draft.unit),
        name: draft.name.trim(),
        phone: draft.phone.trim(),
        ...(draft.email.trim() ? { email: draft.email.trim() } : {}),
        ...(draft.message.trim() ? { message: draft.message.trim() } : {}),
        consent: draft.consent,
    };
}

/** The services a branch offers, in the catalog's order; for home service only those that can be done outside the shop */
export function servicesAt(categories: { services: Service[] }[], branch: Branch | undefined, home = false): Service[] {
    return branch ? categories.flatMap((category) => category.services).filter((service) => branch.serviceIds.includes(service.id) && (!home || service.homeServiceOk)) : [];
}

/** "about 3 days", "1 day"; the same wording the server uses */
export const daysLabel = (days: number): string => (days === 1 ? '1 day' : `about ${days} days`);

/**
 * The day a job dropped off on `start` is done, worked out the way the
 * server does: the drop-off day is day 1 and closed days don't count.
 * Months that were loaded say which days are closed; others fall back to
 * the branch's weekly hours. Null when it's too far out to say.
 */
export function readyOn(start: string, days: number, months: Record<string, MonthAvailability>, weeklyHours: BranchHours[] | undefined): string | null {
    if (days < 1) {
        return null;
    }

    let date = parseDate(start);
    let counted = 0;

    for (let step = 0; step < 400; step++) {
        const day = isoDate(date);
        const month = months[day.slice(0, 7)];
        const isoWeekday = date.getDay() === 0 ? 7 : date.getDay();
        const open = month && day >= month.first && day <= month.last ? month.days[day] !== 'closed' : weeklyHours?.length ? Boolean(weeklyHours.find((entry) => entry.day === isoWeekday)?.open) : true;

        if (open && ++counted === days) {
            return step > 120 ? null : day;
        }

        date = addDays(date, 1);
    }

    return null;
}

/** The site's rules: 1 to 5 stars, at least 10 characters, one picture */
export const reviewProblems = (rating: number, body: string): Record<string, string> => ({
    ...(rating < 1 ? { rating: 'Pick a star rating.' } : {}),
    ...(body.trim().length < 10 ? { body: 'Write at least 10 characters.' } : {}),
});

