import { expect, test } from '@playwright/test';

import { adminLogin, TEAM, unit } from './admin-support';

/**
 * What each role may open (app/Support/Access.php): 200 to see it, 403 when the role has no
 * such area. A super admin opens everything.
 */
const PAGES: Record<string, { path: string; roles: ReadonlyArray<keyof typeof TEAM> }> = {
    dashboard: { path: '/admin', roles: ['owner', 'operations', 'sales', 'manager'] },
    queue: { path: '/admin/queue', roles: ['owner', 'operations', 'sales', 'manager'] },
    bookings: { path: '/admin/bookings', roles: ['owner', 'operations', 'sales', 'manager'] },
    customers: { path: '/admin/customers', roles: ['owner', 'operations', 'sales', 'manager'] },
    warranty: { path: '/admin/warranty', roles: ['owner', 'operations', 'sales', 'manager'] },
    'manual transaction': { path: '/admin/manual-transaction', roles: ['owner', 'sales', 'manager'] },
    reviews: { path: '/admin/reviews', roles: ['owner', 'sales', 'manager'] },
    closures: { path: '/admin/branch-blocks', roles: ['owner', 'manager'] },
    branches: { path: '/admin/branches', roles: ['owner'] },
    services: { path: '/admin/services', roles: ['owner'] },
    photos: { path: '/admin/photos', roles: ['owner'] },
    users: { path: '/admin/users', roles: ['owner'] },
    'email settings': { path: '/admin/email-settings', roles: ['owner'] },
    'booking rules': { path: '/admin/booking-rules', roles: ['owner'] },
    security: { path: '/admin/security', roles: ['owner'] },
};

for (const role of ['owner', 'operations', 'sales', 'manager'] as const) {
    test(`role access: ${role} opens exactly its own pages and gets 403 elsewhere`, async ({ page }) => {
        await adminLogin(page, TEAM[role]);

        const wrong: string[] = [];

        for (const [name, { path, roles }] of Object.entries(PAGES)) {
            const status = (await page.goto(path))?.status();
            const expected = roles.includes(role) ? 200 : 403;

            if (status !== expected) {
                wrong.push(`${name} (${path}): got ${status}, expected ${expected}`);
            }
        }

        expect(wrong).toEqual([]);
    });
}

test.describe('role differences on a booking', () => {
    test('sales sees the sales actions, a detailer only the queue steps', async ({ page, browser }, info) => {
        const name = unit(info, 'Roles');

        const check = async (email: string) => {
            const context = await browser.newContext({ baseURL: info.project.use.baseURL ?? undefined, viewport: info.project.use.viewport ?? undefined });
            const other = await context.newPage();
            await adminLogin(other, email);
            await other.goto('/admin/bookings');
            await other.getByPlaceholder('Search').first().fill(name);
            await other.locator('table tbody tr').filter({ hasText: name }).getByRole('link', { name: 'View' }).click();
            await other.waitForURL(/\/admin\/bookings\/\d+/);
            await other.waitForLoadState('networkidle');
            const buttons = await other.getByRole('button').allInnerTexts();
            await context.close();

            return buttons.map((text) => text.trim());
        };

        void page;
        const sales = await check(TEAM.sales);
        const operations = await check(TEAM.operations);

        expect(operations).toContain('Arrived');
        expect(sales).not.toContain('Arrived');
    });
});
