import { appendFileSync, mkdirSync } from 'node:fs';

import { expect, type Page, test, type TestInfo } from '@playwright/test';

import { adminLogin, adminOpen, row, submitLogin, TEAM, unit } from './admin-support';
import { signIn } from './support';

/**
 * Screenshots for reviewing the Board cards against the running app. Skipped unless REVIEW_DIR is set
 * (a folder on the machine running the tests); each step records what it saw in REVIEW_DIR/log.jsonl.
 * Not a pass/fail test of the app: a step that cannot find its screen writes the failure in the log
 * and moves on, so a missing feature shows up as a missing picture and a log line.
 */
const dir = process.env.REVIEW_DIR;

test.skip(!dir, 'review evidence is made on request (REVIEW_DIR)');

async function step(info: TestInfo, page: Page, card: number, name: string, run: () => Promise<string | void>, options: { phone?: boolean } = {}) {
    if (info.project.name === 'phone' && !options.phone) {
        return;
    }

    mkdirSync(dir!, { recursive: true });
    const file = `${dir}/card${card}-${name}-${info.project.name}.png`;
    let note = '';
    let ok = true;

    try {
        note = (await run()) ?? '';
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: file, fullPage: true });
    } catch (error) {
        ok = false;
        note = String((error as Error).message).split('\n')[0];
        await page.screenshot({ path: `${dir}/card${card}-${name}-${info.project.name}-NOT-FOUND.png` }).catch(() => undefined);
    }

    appendFileSync(`${dir}/log.jsonl`, `${JSON.stringify({ card, name, project: info.project.name, ok, file: ok ? file : null, note })}\n`);
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(150_000);
test.beforeEach(({ page }) => {
    // A screen that is not there fails fast, so the log says so and the next step still runs
    page.setDefaultTimeout(8_000);
});

test('card 16 and 17: roles and two-factor screens', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await step(info, page, 16, 'users-list', () => adminOpen(page, '/admin/users').then(() => undefined));
    await step(info, page, 16, 'user-edit-role-and-areas', async () => {
        await adminOpen(page, '/admin/users');
        await row(page, 'Sales Admin').getByRole('link', { name: 'Edit' }).click();
        await page.waitForLoadState('networkidle');
    });
    await step(info, page, 17, 'profile-two-factor', () => adminOpen(page, '/admin/profile').then(() => undefined));
    await step(info, page, 17, 'security-settings', () => adminOpen(page, '/admin/security').then(() => undefined));
    await step(info, page, 17, 'users-2fa-column', () => adminOpen(page, '/admin/users').then(() => undefined));

    const other = await page.context().browser()!.newContext({ viewport: info.project.use.viewport ?? undefined, baseURL: info.project.use.baseURL ?? undefined });
    const challenge = await other.newPage();
    await step(info, challenge, 17, 'login-authenticator-challenge', async () => {
        await submitLogin(challenge, TEAM.twoFactor);
        await challenge.locator('.fi-one-time-code-input-ctn').waitFor();
    });
    await other.close();
});

test('card 18 and 19: the unit on the booking form, units on the customer', async ({ page }, info) => {
    await signIn(page);
    await step(info, page, 18, 'book-unit-step', async () => {
        await page.goto('/book');
        const main = page.locator('main#main');
        await main.locator('h1, h2').first().waitFor();

        if (await main.getByRole('heading', { name: 'Which branch will you drive to?' }).isVisible()) {
            await main.getByRole('radio', { name: /Cainta/ }).check({ force: true });
            await main.getByRole('button', { name: 'Next', exact: true }).first().click();
        }

        await main.getByRole('heading', { name: 'Which unit are we working on?' }).waitFor();
    }, { phone: true });

    await adminLogin(page, TEAM.owner);
    await step(info, page, 18, 'admin-booking-size-review', async () => {
        await adminOpen(page, '/admin/bookings');
        await page.getByPlaceholder('Search').first().fill(unit(info, 'Roles'));
        await row(page, unit(info, 'Roles')).getByRole('link', { name: 'View' }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: /Change size|Review unit/ }).first().waitFor();
    });
    await step(info, page, 19, 'customer-units-tab', async () => {
        await adminOpen(page, '/admin/customers');
        await row(page, 'E2E Customer').getByRole('link', { name: 'View' }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Units/ }).click();
    });
});

test('card 20 and 21 and 22: arrivals, closures, queue and the heads-up', async ({ page, browser }, info) => {
    await adminLogin(page, TEAM.operations);
    await step(info, page, 21, 'queue-detailer', () => adminOpen(page, '/admin/queue').then(() => undefined), { phone: true });
    await step(info, page, 20, 'bookings-list-estimates', () => adminOpen(page, '/admin/bookings').then(() => undefined));

    await step(info, page, 22, 'ops-changes-ready-date', async () => {
        await adminOpen(page, '/admin/bookings');
        await page.getByPlaceholder('Search').first().fill(unit(info, 'Roles'));
        await row(page, unit(info, 'Roles')).getByRole('link', { name: 'View' }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: /actions/i }).first().click();
        await page.getByRole('menuitem', { name: 'Change ready date' }).click();
        const dialog = page.locator('[role=dialog]');
        const later = new Date(Date.now() + 3 * 86_400_000).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' });
        const field = dialog.getByLabel('Ready around').first();
        await field.click();
        await page.screenshot({ path: `${dir}/card22-picker-open-${info.project.name}.png` });
        // Pick a day three days on (the picker is a calendar; the page's date is from the Manila clock)
        const day = String(new Date(`${later}`).getDate());
        await dialog.getByText(day, { exact: true }).last().click();
        await dialog.getByRole('heading', { name: 'Change ready date' }).click();
        await dialog.locator('button[type=submit]').last().click();
        await page.waitForLoadState('networkidle');
    });

    const sales = await browser.newContext({ viewport: info.project.use.viewport ?? undefined, baseURL: info.project.use.baseURL ?? undefined });
    const salesPage = await sales.newPage();
    salesPage.setDefaultTimeout(8_000);
    await adminLogin(salesPage, TEAM.sales);
    await step(info, salesPage, 22, 'sales-bell-heads-up', async () => {
        await salesPage.getByRole('button', { name: 'Notifications' }).click();
        await salesPage.getByText(/Heads-up/).first().waitFor();
    });
    await sales.close();
});

test('card 23 to 26: rewards, CRM, manual transaction, warranty', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await step(info, page, 23, 'referral-settings', () => adminOpen(page, '/admin/referral').then(() => undefined));
    await step(info, page, 23, 'customer-points-and-redeem', async () => {
        await adminOpen(page, '/admin/customers');
        await row(page, 'E2E Customer').getByRole('link', { name: 'View' }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'Redeem points' }).click();
        await page.locator('[role=dialog]').first().waitFor();
    });
    await step(info, page, 20, 'closures-full-days', async () => {
        await adminOpen(page, '/admin/branch-blocks');
        await page.locator('a, button').filter({ hasText: /^\s*(New|Create|Add)/i }).first().click();
        await page.waitForLoadState('networkidle');
    });
    await step(info, page, 24, 'customers-list-filters', async () => {
        await adminOpen(page, '/admin/customers');
        await page.getByRole('button', { name: /Filter/ }).first().click();
    });
    await step(info, page, 24, 'customer-profile', async () => {
        await adminOpen(page, '/admin/customers');
        await row(page, 'E2E Customer').getByRole('link', { name: 'View' }).click();
    });
    await step(info, page, 24, 'customer-edit-sales-rep', async () => {
        await adminOpen(page, '/admin/customers');
        await row(page, 'E2E Customer').getByRole('link', { name: 'View' }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('link', { name: 'Edit' }).first().click();
    });
    await step(info, page, 25, 'manual-transaction-form', () => adminOpen(page, '/admin/manual-transaction').then(() => undefined), { phone: true });
    await step(info, page, 26, 'warranty-checker', () => adminOpen(page, '/admin/warranty').then(() => undefined));
    await step(info, page, 26, 'backjob-types', () => adminOpen(page, '/admin/backjob-types').then(() => undefined));
    await step(info, page, 26, 'service-warranty-rules', async () => {
        await adminOpen(page, '/admin/services');
        await page.getByRole('link', { name: 'Edit' }).first().click();
    });
});

test('card 28 to 39: inbox, site content, dashboards, Facebook pages', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await step(info, page, 28, 'inbox', () => adminOpen(page, '/admin/inbox').then(() => undefined), { phone: true });
    await step(info, page, 39, 'meta-settings', () => adminOpen(page, '/admin/meta').then(() => undefined));
    await step(info, page, 39, 'branch-facebook-page-tab', async () => {
        await adminOpen(page, '/admin/branches');
        await page.getByRole('link', { name: 'Edit' }).first().click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Facebook/ }).click();
    });
    await step(info, page, 29, 'photos', () => adminOpen(page, '/admin/photos').then(() => undefined));
    await step(info, page, 29, 'videos', () => adminOpen(page, '/admin/videos').then(() => undefined));
    await step(info, page, 29, 'reviews', () => adminOpen(page, '/admin/reviews').then(() => undefined));
    await step(info, page, 29, 'site-gallery', async () => {
        await page.goto('/gallery');
        await page.locator('main#main h1, main#main h2').first().waitFor();
    });
    await step(info, page, 30, 'dashboard-owner', () => adminOpen(page, '/admin').then(() => undefined), { phone: true });
    await step(info, page, 36, 'dashboard-all-branches', async () => {
        await adminOpen(page, '/admin');
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });
});
