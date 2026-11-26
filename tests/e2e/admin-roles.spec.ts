import { expect, test } from '@playwright/test';

import { adminLogin, adminOpen, TEAM, unit } from './admin-support';
import { evidence } from './evidence';

/**
 * What each role gets when it opens an old /admin/<page> link (app/Support/Panels.php,
 * app/Providers/Filament/PanelPages.php, AuthenticatePanel):
 *  - a page that lives in a panel the person may open: redirected there (a super admin opens all four);
 *  - a page of their own panel they have no area for: 403 in that panel (policy);
 *  - a page that lives only in a panel they cannot open: redirected to their own home.
 * `where` is the expected final path, `status` the final status.
 */
type Outcome = { path: string; status: 200 | 403 };

const SLUGS = ['queue', 'bookings', 'customers', 'warranty', 'manual-transaction', 'reviews', 'branch-blocks', 'inbox', 'branches', 'services', 'photos', 'users', 'booking-rules', 'site-settings', 'referral', 'email-settings', 'security'] as const;
type Slug = (typeof SLUGS)[number];

const ok = (path: string): Outcome => ({ path, status: 200 });
const denied = (path: string): Outcome => ({ path, status: 403 });

const OWNER_ONLY: Slug[] = ['branches', 'services', 'photos', 'users', 'booking-rules', 'site-settings', 'referral', 'email-settings', 'security'];

const EXPECTED: Record<'owner' | 'operations' | 'sales' | 'manager', Record<Slug, Outcome>> = {
    owner: {
        queue: ok('/operations/queue'),
        bookings: ok('/sales/bookings'),
        customers: ok('/sales/customers'),
        warranty: ok('/sales/warranty'),
        'manual-transaction': ok('/sales/manual-transaction'),
        reviews: ok('/owner/reviews'),
        'branch-blocks': ok('/owner/branch-blocks'),
        inbox: ok('/sales/inbox'),
        branches: ok('/owner/branches'),
        services: ok('/owner/services'),
        photos: ok('/owner/photos'),
        users: ok('/owner/users'),
        'booking-rules': ok('/owner/booking-rules'),
        'site-settings': ok('/owner/site-settings'),
        referral: ok('/owner/referral'),
        'email-settings': ok('/system/email-settings'),
        security: ok('/system/security'),
    },
    operations: {
        queue: ok('/operations/queue'),
        bookings: ok('/operations/bookings'),
        customers: ok('/operations/customers'),
        warranty: ok('/operations/warranty'),
        'manual-transaction': denied('/operations/manual-transaction'),
        reviews: denied('/operations/reviews'),
        'branch-blocks': denied('/operations/branch-blocks'),
        inbox: denied('/operations/inbox'),
        ...Object.fromEntries(OWNER_ONLY.map((slug) => [slug, ok('/operations/queue')])),
    } as Record<Slug, Outcome>,
    sales: {
        queue: ok('/sales'),
        bookings: ok('/sales/bookings'),
        customers: ok('/sales/customers'),
        warranty: ok('/sales/warranty'),
        'manual-transaction': ok('/sales/manual-transaction'),
        reviews: ok('/sales/reviews'),
        'branch-blocks': denied('/sales/branch-blocks'),
        inbox: ok('/sales/inbox'),
        ...Object.fromEntries(OWNER_ONLY.map((slug) => [slug, ok('/sales')])),
    } as Record<Slug, Outcome>,
    manager: {
        queue: ok('/operations/queue'),
        bookings: ok('/operations/bookings'),
        customers: ok('/operations/customers'),
        warranty: ok('/operations/warranty'),
        'manual-transaction': ok('/operations/manual-transaction'),
        reviews: ok('/operations/reviews'),
        'branch-blocks': ok('/operations/branch-blocks'),
        inbox: ok('/operations/inbox'),
        ...Object.fromEntries(OWNER_ONLY.map((slug) => [slug, ok('/operations/queue')])),
    } as Record<Slug, Outcome>,
};

const HOME = { owner: '/owner', operations: '/operations/queue', sales: '/sales', manager: '/operations/queue' } as const;

for (const role of ['owner', 'operations', 'sales', 'manager'] as const) {
    test(`role access: ${role} lands in its own panel and old /admin links go where the panel split sends them`, async ({ page }, info) => {
        await adminLogin(page, TEAM[role]);
        expect(new URL(page.url()).pathname.replace(/\/$/, '')).toBe(HOME[role]);

        if (info.project.name === 'desktop') {
            await expect(page.locator('.fi-sidebar')).toBeVisible();
            await evidence(page, info, 'card16', `landing-${role}`);
        }

        const wrong: string[] = [];

        for (const slug of SLUGS) {
            const response = await page.goto(`/admin/${slug}`);
            const expected = EXPECTED[role][slug];
            const path = new URL(page.url()).pathname;

            if (response?.status() !== expected.status || path !== expected.path) {
                wrong.push(`/admin/${slug}: got ${response?.status()} ${path}, expected ${expected.status} ${expected.path}`);
            }
        }

        expect(wrong).toEqual([]);
    });
}

test('the super admin also opens the system panel, with its own sidebar; nobody else can', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await adminOpen(page, '/system');
    await expect(page).toHaveURL(/\/system\/?$/);
    await expect(page.locator('.fi-sidebar')).toContainText('Email');
    await evidence(page, info, 'card16', 'landing-system');
});

test('a sales admin asking for the system panel is sent to its own home', async ({ page }) => {
    await adminLogin(page, TEAM.sales);
    await adminOpen(page, '/system');
    await expect(page).toHaveURL(/\/sales\/?$/);
});

test.describe('role differences on a booking', () => {
    test('sales sees the sales actions, a detailer only the queue steps', async ({ page, browser }, info) => {
        const name = unit(info, 'Roles');

        const check = async (email: string, panel: 'sales' | 'operations') => {
            const context = await browser.newContext({ baseURL: info.project.use.baseURL ?? undefined, viewport: info.project.use.viewport ?? undefined });
            const other = await context.newPage();
            await adminLogin(other, email);
            await other.goto(`/${panel}/bookings`);
            await other.getByPlaceholder('Search').first().fill(name);
            await other.locator('table tbody tr').filter({ hasText: name }).getByRole('link', { name: 'View' }).click();
            await other.waitForURL(new RegExp(`/${panel}/bookings/\\d+`));
            await other.waitForLoadState('networkidle');
            const buttons = await other.getByRole('button').allInnerTexts();
            await context.close();

            return buttons.map((text) => text.trim());
        };

        void page;
        const sales = await check(TEAM.sales, 'sales');
        const operations = await check(TEAM.operations, 'operations');

        expect(operations).toContain('Arrived');
        expect(sales).not.toContain('Arrived');
    });
});
