import type { StaffUser } from '../api/types';

type Who = Pick<StaffUser, 'areas' | 'isOwner'>;

/** Money is for people with bookings.sales. The server already leaves it out; this is the second lock. */
export const canSeeMoney = (user: Who | null): boolean => !!user && user.areas.includes('bookings.sales');

/** Customer phone and email: customers.view or bookings.sales */
export const canSeeContact = (user: Who | null): boolean => !!user && (user.areas.includes('customers.view') || user.areas.includes('bookings.sales'));

/** "Head detailer · Cainta": the role and the branch (or how many) */
export function roleChip(user: Pick<StaffUser, 'roleLabel' | 'isOwner' | 'branches'>): string {
    if (user.isOwner) return user.roleLabel;
    if (user.branches.length === 1) return `${user.roleLabel} · ${user.branches[0].name}`;

    return user.branches.length > 1 ? `${user.roleLabel} · ${user.branches.length} branches` : user.roleLabel;
}
