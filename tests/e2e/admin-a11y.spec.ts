import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { adminLogin, adminOpen, TEAM } from './admin-support';

/**
 * Accessibility smoke for the admin (Filament): axe (WCAG A and AA) on the main pages as a
 * super admin. Filament's own markup is not ours to change here; real violations found on a page
 * are listed in KNOWN as "page:rule" with what they are, so they stay visible and the rest of
 * the rules still guard the page. Never skip a page.
 */
const KNOWN: Record<string, string> = {
    // Filament's table filter button: its title ("Filter") is not part of its visible label
    'bookings:label-content-name-mismatch': 'the filter button in the table header',
    'customers:label-content-name-mismatch': 'the filter button in the table header',
    // The Services column of the bookings table renders its service chips as links with no text (12 on the seeded list)
    'bookings:link-name': 'links with no text in the Services column',
    // The select's clear (x) button is under 24 px
    'manual-transaction:target-size': 'the clear button of a select field',
};

const PAGES = [
    { name: 'login', path: '/admin/login', signedIn: false },
    { name: 'dashboard', path: '/admin' },
    { name: 'queue', path: '/admin/queue' },
    { name: 'bookings', path: '/admin/bookings' },
    { name: 'customers', path: '/admin/customers' },
    { name: 'warranty', path: '/admin/warranty' },
    { name: 'manual-transaction', path: '/admin/manual-transaction' },
    { name: 'users', path: '/admin/users' },
    { name: 'email-settings', path: '/admin/email-settings' },
    { name: 'branches', path: '/admin/branches' },
    { name: 'reviews', path: '/admin/reviews' },
] as const;

for (const { name, path, ...rest } of PAGES) {
    test(`admin accessibility: ${name}`, async ({ page }) => {
        if ((rest as { signedIn?: boolean }).signedIn === false) {
            await page.goto(path);
        } else {
            await adminLogin(page, TEAM.owner);
            await adminOpen(page, path);
        }

        await page.waitForLoadState('networkidle');
        const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        const found = violations.filter((violation) => !(`${name}:${violation.id}` in KNOWN));

        expect(
            found.map((violation) => `${violation.id} (${violation.impact}): ${violation.help} - ${violation.nodes.length} element(s), e.g. ${violation.nodes[0]?.target.join(' ')}`),
        ).toEqual([]);
    });
}
