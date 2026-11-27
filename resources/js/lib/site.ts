import type { Branch, BranchHours, Category, Estimate, Photo, Service, VehicleType } from '../types';

/**
 * Everything the site shows, embedded in the page by SiteController.
 */
export const site = window.APP826;

export const categories: Category[] = site.catalog.categories;

/** Every service, in the admin's order, with its category */
export const services: (Service & { category: Category })[] = categories.flatMap((category) => category.services.map((service) => ({ ...service, category })));

export const serviceById = (id: number) => services.find((service) => service.id === id);

export const serviceBySlug = (slug: string | null | undefined) => services.find((service) => service.slug === slug);

export const branchById = (id: number | null | undefined) => site.branches.find((branch) => branch.id === id);

export const branchBySlug = (slug: string | null | undefined) => site.branches.find((branch) => branch.slug === slug);

/** Branches that take online reservations now */
export const bookableBranches = site.branches.filter((branch) => branch.bookable);

/** Branches that come to the customer */
export const homeBranches = site.branches.filter((branch) => branch.homeService.offered);

/** The branch step's list: from a service's link, only the branches that offer it */
export const branchChoices = ({ onlyService, serviceIds }: { onlyService?: number | null; serviceIds: number[] }) =>
    onlyService && serviceIds.includes(onlyService) ? site.branches.filter((branch) => branch.serviceIds.includes(onlyService)) : site.branches;

/** Open branches where a service can be booked */
export const branchesOffering = (service: Service): Branch[] => site.branches.filter((branch) => branch.serviceIds.includes(service.id));

/** The services a branch offers, in the catalog's order; for home service only those that can be done outside the shop */
export function servicesAt(branch: Branch | undefined, { home = false } = {}) {
    if (!branch) {
        return [];
    }

    return services.filter((service) => branch.serviceIds.includes(service.id) && (!home || service.homeServiceOk));
}

/** "about 3 days", "1 day"; the same wording the server uses */
export const daysLabel = (days: number): string => (days === 1 ? '1 day' : `about ${days} days`);

/** The estimate for a set of services: their days added up (the server's own answer replaces it when it comes) */
export function estimateOf(serviceIds: number[]): Estimate | null {
    const days = serviceIds.reduce((sum, id) => sum + (serviceById(id)?.estimateDays ?? 0), 0);

    return days > 0 ? { days, label: daysLabel(days) } : null;
}

/** The kinds of unit to pick from; the server's list, with the contract's seven as a fallback */
export const vehicleTypes: VehicleType[] =
    site.vehicleTypes && site.vehicleTypes.length
        ? site.vehicleTypes
        : [
              { key: 'sedan', label: 'Sedan' },
              { key: 'hatchback', label: 'Hatchback' },
              { key: 'coupe', label: 'Coupe' },
              { key: 'suv', label: 'SUV' },
              { key: 'pickup', label: 'Pickup' },
              { key: 'van', label: 'Van' },
              { key: 'motorcycle', label: 'Motorcycle' },
          ];

export const vehicleTypeLabel = (key: string | null | undefined) => vehicleTypes.find((type) => type.key === key)?.label ?? null;

/** What a category's services take, as one line: "1 to 5 days"; null when no service has an estimate */
export function categoryDays(category: Category): string | null {
    const days = category.services.map((service) => service.estimateDays).filter((value): value is number => value !== null && value > 0);

    if (days.length === 0) {
        return null;
    }

    const low = Math.min(...days);
    const high = Math.max(...days);

    return low === high ? daysLabel(low) : `${low} to ${high} days`;
}

/**
 * 10500 → "₱10,500".
 */
export function peso(amount: number): string {
    return `₱${amount.toLocaleString('en-PH')}`;
}

/** "08:00" → "8:00 AM" */
export function clock(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const [hours, minutes] = value.split(':').map(Number);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const hour = hours % 12 || 12;

    return `${hour}:${String(minutes).padStart(2, '0')} ${suffix}`;
}

/** Today's ISO weekday in Manila (1 = Monday … 7 = Sunday) */
export function manilaWeekday(): number {
    const name = new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone: 'Asia/Manila' }).format(new Date());

    return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(name) + 1;
}

export const hoursToday = (branch: Branch): BranchHours | undefined => branch.hours.find((day) => day.day === manilaWeekday());

/** "8:00 AM – 6:00 PM", or null when closed */
export const hoursRange = (hours: BranchHours | undefined): string | null => (hours?.open && hours.close ? `${clock(hours.open)} – ${clock(hours.close)}` : null);

/**
 * The week in runs of days with the same hours: "Monday – Saturday",
 * "Sunday" (for a short summary; the branch page has every day).
 */
export function hoursSummary(branch: Branch): { days: string; hours: string | null }[] {
    const runs: { from: BranchHours; to: BranchHours }[] = [];

    for (const day of branch.hours) {
        const last = runs[runs.length - 1];

        if (last && last.to.open === day.open && last.to.close === day.close && last.to.day === day.day - 1) {
            last.to = day;
        } else {
            runs.push({ from: day, to: day });
        }
    }

    return runs.map(({ from, to }) => ({ days: from.day === to.day ? from.label : `${from.label} – ${to.label}`, hours: hoursRange(from) }));
}

/** Site-wide phone, for tel: and sms: links */
export const phone = site.settings.phone && site.settings.phoneHref ? { label: site.settings.phone, href: site.settings.phoneHref } : null;

/** Where to message 826: Messenger when set, else the Facebook page */
export const messageUrl = site.settings.messenger ?? site.settings.facebook;

/**
 * Photo categories that have photos, in the admin's order.
 */
export const photoCategories = Object.keys(site.photoCategories).filter((category) => site.photos.some((photo) => photo.category === category));

export const featuredPhotos: Photo[] = site.photos.some((photo) => photo.isFeatured) ? site.photos.filter((photo) => photo.isFeatured) : site.photos.slice(0, 8);

export function photoAlt(photo: Photo): string {
    if (photo.caption) {
        return photo.caption;
    }

    const category = site.photoCategories[photo.category]?.toLowerCase() ?? 'our work';

    return `${site.shortName}: ${category}`;
}

/** A path on this site's API, under the folder it's served from */
export const apiUrl = (path: string) => `${site.base}/api${path}`;
