import { appendFileSync, mkdirSync } from 'node:fs';

import { expect, type Page, test, type TestInfo } from '@playwright/test';

import { actionThenConfirm, adminLogin, adminOpen, openBooking, row, submitLogin, TEAM, unit } from './admin-support';
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

/**
 * Second review (cards 19, 23, 25, 30) after the fixes. These need the extra sample data
 * (ReviewEvidenceSeeder, made when REVIEW_DIR is set). Each step returns the text it saw, which
 * lands in log.jsonl next to the picture.
 */
const seen = async (page: Page, selector = 'main') => ((await page.locator(selector).first().innerText()).replace(/\s+/g, ' ').trim()).slice(0, 1800);
/** Scroll down so the dashboard's lazy widgets load, then wait for the last "Loading..." to go */
const loadAll = async (page: Page) => {
    for (let y = 0; y < 8; y++) {
        await page.mouse.wheel(0, 1200);
        await page.waitForTimeout(400);
    }

    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Loading...')).toHaveCount(0, { timeout: 20_000 });
    await page.evaluate(() => window.scrollTo(0, 0));
};
const tab = async (page: Page, name: RegExp, expected: RegExp) => {
    await page.getByRole('tab', { name }).click();
    await page.getByText(expected).first().waitFor({ timeout: 15_000 });
    await page.waitForLoadState('networkidle');
};
/** A Filament select: open the field's button, then pick the option (by text, or the nth) */
const choose = async (page: Page, label: string, option: string | number, search?: string) => {
    const field = page.locator('.fi-fo-field').filter({ has: page.locator('label').filter({ hasText: new RegExp(`^\\s*${label}\\*?\\s*$`) }) }).first();
    await field.locator('button').first().click();

    if (search) {
        await page.keyboard.type(search);
        await page.waitForTimeout(800);
    }

    const options = page.getByRole('option');
    await (typeof option === 'number' ? options.nth(option) : options.filter({ hasText: option }).first()).click();
};
const openCustomer = async (page: Page, name: string) => {
    await adminOpen(page, '/admin/customers');
    await page.getByPlaceholder('Search').first().fill(name);
    await expect(row(page, name)).toHaveCount(1);
    await row(page, name).getByRole('link', { name: 'View' }).click();
    await page.waitForLoadState('networkidle');
};

test('review2 card 19: units, jobs count, last service, history', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await step(info, page, 19, 'r2-owner-units-tab', async () => {
        await openCustomer(page, 'E2E Customer');
        await tab(page, /Units/, /Last service/);
        return `OWNER ${await seen(page)}`;
    }, { phone: true });
    await step(info, page, 19, 'r2-owner-unit-history', async () => {
        await row(page, 'CRM1234').getByRole('button', { name: 'History' }).click();
        await page.locator('[role=dialog]').getByText('Reference').waitFor();
        return `OWNER HISTORY ${await seen(page, '[role=dialog]')}`;
    });

    const manager = await page.context().browser()!.newContext({ viewport: info.project.use.viewport ?? undefined, baseURL: info.project.use.baseURL ?? undefined });
    const managerPage = await manager.newPage();
    managerPage.setDefaultTimeout(8_000);
    await adminLogin(managerPage, TEAM.manager);
    await step(info, managerPage, 19, 'r2-manager-unit-history-branch-scoped', async () => {
        await openCustomer(managerPage, 'E2E Customer');
        await tab(managerPage, /Units/, /Last service/);
        const table = `MANAGER UNITS ${await seen(managerPage)}`;
        await row(managerPage, 'CRM1234').getByRole('button', { name: 'History' }).click();
        await managerPage.locator('[role=dialog]').getByText('Reference').waitFor();

        return `${table} || MANAGER HISTORY ${await seen(managerPage, '[role=dialog]')}`;
    });
    await manager.close();
});

test('review2 card 23 and 30: dashboard referrers, rep column, points', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await step(info, page, 23, 'r2-owner-dashboard-referrers-and-reps', async () => {
        await adminOpen(page, '/admin');
        await loadAll(page);
        await page.getByText('Top referrers').first().waitFor();
        return `OWNER ${await seen(page)}`;
    }, { phone: true });
    await step(info, page, 30, 'r2-owner-dashboard-sales-points', async () => {
        await adminOpen(page, '/admin');
        await loadAll(page);
        await page.getByText('Points outstanding').first().scrollIntoViewIfNeeded();
        return `OWNER ${await seen(page)}`;
    }, { phone: true });
    await step(info, page, 23, 'r2-customer-referral-tab-referrer', async () => {
        await openCustomer(page, 'Maria Referrer');
        await tab(page, /Referral/, /Used by|No one has used/);
        return `MARIA ${await seen(page)}`;
    });

    const manager = await page.context().browser()!.newContext({ viewport: info.project.use.viewport ?? undefined, baseURL: info.project.use.baseURL ?? undefined });
    const managerPage = await manager.newPage();
    managerPage.setDefaultTimeout(8_000);
    await adminLogin(managerPage, TEAM.manager);
    await step(info, managerPage, 30, 'r2-manager-dashboard-scoped', async () => {
        await adminOpen(managerPage, '/admin');
        await loadAll(managerPage);
        await managerPage.getByText('Points outstanding').first().scrollIntoViewIfNeeded();
        return `MANAGER ${await seen(managerPage)}`;
    });
    await manager.close();
});

test('review2 card 25: manual transaction with a referral code', async ({ page }, info) => {
    test.skip(info.project.name === 'phone', 'the saving steps run once, on desktop');
    await adminLogin(page, TEAM.sales);

    const fill = async (customer: string, plate: string, code: string, status = 'Ready') => {
        await adminOpen(page, '/admin/manual-transaction');
        await choose(page, 'Customer', customer, customer);
        await page.locator('[id$="vehicle_year"]').fill('2022');
        await page.locator('[id$="vehicle_model"]').fill('Toyota Vios');
        await choose(page, 'Type', 0);
        await page.locator('[id$="plate_number"]').fill(plate);
        await choose(page, 'Size class', 1);
        await page.getByText('Exterior detailing').first().click();
        await page.waitForLoadState('networkidle');
        await choose(page, 'Status', status);
        await page.locator('[id$="referral_code"]').fill(code);
    };
    const save = async () => {
        await page.getByRole('button', { name: 'Save transaction' }).click();
        await page.waitForLoadState('networkidle');
    };

    await step(info, page, 25, 'r2-form-with-referral-field', async () => {
        await adminOpen(page, '/admin/manual-transaction');
        await page.locator('[id$="referral_code"]').waitFor();
        return `FORM ${await seen(page)}`;
    });
    await step(info, page, 25, 'r2-bad-code-error', async () => {
        await fill('Fresh Friend', 'FRE 9001', 'NOPE-0000');
        await save();
        await page.getByText(/couldn’t find that referral code/).waitFor();
        return `URL ${page.url()} :: ${await page.getByText(/couldn’t find that referral code/).innerText()}`;
    });
    await step(info, page, 25, 'r2-own-code-error', async () => {
        await fill('Maria Referrer', 'MAR 9002', 'MARIA-7K3Q');
        await save();
        await page.getByText(/own referral code/).waitFor();
        return `URL ${page.url()} :: ${await page.getByText(/own referral code/).innerText()}`;
    });
    await step(info, page, 25, 'r2-not-first-booking-error', async () => {
        await fill('E2E Customer', 'CRM1234', 'MARIA-7K3Q');
        await save();
        await page.getByText(/Referral codes are for your first booking only/).first().waitFor();
        return `URL ${page.url()} :: ${await page.getByText(/Referral codes are for your first booking only/).first().innerText()}`;
    });
    await step(info, page, 25, 'r2-nothing-saved-after-errors', async () => {
        await adminOpen(page, '/admin/bookings');
        const found = await page.locator('table tbody tr').filter({ hasText: /FRE 9001|MAR 9002/ }).count();
        return `Bookings with the rejected plates: ${found}`;
    });
    await step(info, page, 25, 'r2-valid-code-saved-ready', async () => {
        await fill('Fresh Friend', 'FRE 9001', 'maria-7k3q');
        await save();
        await page.waitForURL(/\/admin\/bookings\/\d+/);
        await page.waitForLoadState('networkidle');
        return `SAVED ${await seen(page)}`;
    });
    await step(info, page, 25, 'r2-referral-tab-pending', async () => {
        await openCustomer(page, 'Maria Referrer');
        await tab(page, /Referral/, /Used by|No one has used/);
        return `MARIA BEFORE EXIT ${await seen(page)}`;
    });
    // The sales role cannot move the queue, so the owner marks the job exited
    const owner = await page.context().browser()!.newContext({ viewport: info.project.use.viewport ?? undefined, baseURL: info.project.use.baseURL ?? undefined });
    const ownerPage = await owner.newPage();
    ownerPage.setDefaultTimeout(8_000);
    await adminLogin(ownerPage, TEAM.owner);
    await step(info, ownerPage, 25, 'r2-exit-awards-points-once', async () => {
        await openBooking(ownerPage, 'Fresh Friend');
        await actionThenConfirm(ownerPage, 'Exited');
        await expect(ownerPage.getByRole('button', { name: 'Exited', exact: true })).toHaveCount(0);
        await openCustomer(ownerPage, 'Maria Referrer');
        await tab(ownerPage, /Referral/, /Used by|No one has used/);

        return `MARIA AFTER EXIT ${await seen(ownerPage)}`;
    });
    await owner.close();
});
