import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { expect, type Page, test } from '@playwright/test';

import { adminLogin, adminOpen, submitLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, isPhone, watchProblems } from './support';

/** The React admin's Queue (/console/operations/queue) */
const flow = `http://127.0.0.1:${process.env.E2E_FLOW_PORT}`;
const column = (page: Page, status: string) => page.locator(`section[aria-labelledby="queue-${status}"]`);
const card = (page: Page, status: string, name: string) => column(page, status).locator('article').filter({ hasText: name });

async function openQueue(page: Page, who: string = TEAM.operations) {
    await adminLogin(page, who);
    await page.goto('/console/operations/queue');
    await expect(column(page, 'confirmed')).toBeVisible();
}

test.beforeAll(({}, info) => {
    // Into the throwaway database tests/e2e/serve.sh made; nothing is published to Flow from here
    execFileSync('php', ['artisan', 'db:seed', '--class=E2eConsoleSeeder', '--force', '--no-interaction'], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), E2E_PROJECT: info.project.name, FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '' },
        stdio: 'pipe',
    });
});

test('a guest signs in on the console sign-in page and lands back on the queue', async ({ page }) => {
    await page.goto('/console/operations/queue');
    await expect(page).toHaveURL(/\/console\/login$/);

    // Fill the form where the redirect left us, so the intended address is kept
    await page.getByLabel('Email').fill(TEAM.operations);
    await page.locator('input[name="password"]').fill('password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();

    await expect(page).toHaveURL(/\/console\/operations\/queue$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Queue' })).toBeVisible();
});

test('a detailer says who is on a unit and moves it one step', async ({ page }, info) => {
    const name = `E2E Console ${info.project.name}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.operations);

    // The panel's home is its queue
    await page.goto('/console/operations');
    await expect(page).toHaveURL(/\/console\/operations\/queue$/);
    await expect(page.getByRole('status').filter({ hasText: /Dropping off today: \d+ of 1/ })).toBeVisible();
    const booked = card(page, 'confirmed', name);
    await expect(booked).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await booked.getByRole('button', { name: /^Assign someone to/ }).click();
    const dialog = page.getByRole('dialog', { name: 'Who is on this unit?' });
    await expect(dialog.getByText('Type the name or names. Leave blank to clear.')).toBeVisible();
    await dialog.getByRole('textbox', { name: 'Assigned to' }).fill('  Jun, Ben ');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(dialog).toBeHidden();
    await expect(booked.getByText('Assigned to Jun, Ben')).toBeVisible();

    await booked.getByRole('button', { name: /^Change who is on/ }).click();
    await dialog.getByRole('textbox', { name: 'Assigned to' }).fill('');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(booked.getByText(/Assigned to/)).toHaveCount(0);

    // Cancel changes nothing
    await booked.getByRole('button', { name: 'Mark arrived' }).click();
    const question = page.getByRole('alertdialog', { name: 'Mark arrived?' });
    await question.getByRole('button', { name: 'Cancel' }).click();
    await expect(question).toBeHidden();
    await expect(booked).toBeVisible();

    await booked.getByRole('button', { name: 'Mark arrived' }).click();
    await question.getByRole('button', { name: 'Mark arrived' }).click();
    await expect(card(page, 'arrived', name)).toBeVisible();
    await expect(booked).toHaveCount(0);
    // The keyboard follows the unit to its new column
    await expect(card(page, 'arrived', name)).toBeFocused();
    await expect(page.getByText(`${name}: Arrived`).first()).toBeVisible();
    await expect(card(page, 'arrived', name).getByRole('button', { name: 'Mark in progress' })).toBeVisible();
    expect(problems()).toEqual([]);
});

test('a card opens that booking in the new admin', async ({ page }) => {
    await openQueue(page);
    const first = page.locator('article[data-card]').first();
    const id = await first.getAttribute('data-card');
    const link = first.getByRole('link').first();
    const customer = (await link.locator('span').nth(1).innerText()).trim();

    await link.click();

    await expect(page).toHaveURL(new RegExp(`/console/operations/bookings/${id}$`));
    await expect(page.getByRole('main')).toContainText(customer);
});

test('day navigation: earlier, later, a typed date and today', async ({ page }) => {
    await openQueue(page);
    const heading = page.getByRole('heading', { level: 2 }).first();
    const today = (await heading.innerText()).trim();

    await page.getByRole('button', { name: 'Later' }).click();
    await expect(heading).not.toHaveText(today);
    await expect(page).toHaveURL(/[?&]date=\d{4}-\d{2}-\d{2}/);
    await page.getByRole('button', { name: 'Earlier' }).click();
    await expect(heading).toHaveText(today);

    const date = page.getByRole('textbox', { name: 'Jump to a day' });
    await date.fill('2027-01-04');
    await date.press('Enter');
    await expect(heading).toHaveText('Monday, Jan 4, 2027');
    await expect(page.getByRole('status').filter({ hasText: 'Dropping off on Monday, Jan 4, 2027' })).toBeVisible();

    await page.getByRole('button', { name: /^Open the calendar/ }).click();
    await expect(page.getByRole('grid')).toBeVisible();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Enter');
    await expect(heading).toHaveText('Tuesday, Jan 5, 2027');

    await page.getByRole('button', { name: 'Today' }).click();
    await expect(heading).toHaveText(today);
});

test('a move in one browser shows in another without a reload', async ({ page, browser }, info) => {
    const name = `E2E ConsoleLive ${info.project.name}`;
    const other = await browser.newContext({ baseURL: info.project.use.baseURL, viewport: info.project.use.viewport, reducedMotion: 'reduce' });
    const watcher = await other.newPage();
    const sockets = async () => ((await (await fetch(`${flow}/_test/state`)).json()) as { sockets: number }).sockets;
    const before = await sockets();

    try {
        await openQueue(watcher, TEAM.manager);
        await expect.poll(sockets, { timeout: 15_000 }).toBeGreaterThan(before);
        await watcher.evaluate(() => ((window as unknown as { __mark: string }).__mark = 'same page'));
        // Subscribed a moment after the socket opens
        await watcher.waitForTimeout(1000);

        await openQueue(page);
        await card(page, 'confirmed', name).getByRole('button', { name: 'Mark arrived' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Mark arrived' }).click();
        await expect(card(page, 'arrived', name)).toBeVisible();

        await expect(card(watcher, 'arrived', name)).toBeVisible();
        expect(await watcher.evaluate(() => (window as unknown as { __mark?: string }).__mark)).toBe('same page');
    } finally {
        await other.close();
    }
});

test('the sidebar has the Filament panel’s labels, and the others open the current admin', async ({ page }, info) => {
    await adminLogin(page, TEAM.operations);
    await adminOpen(page, '/operations/queue');
    const filament = (await page.locator('.fi-sidebar-nav .fi-sidebar-item-label').allTextContents()).map((label) => label.trim());

    await page.goto('/console/operations/queue');
    await expect(column(page, 'confirmed')).toBeVisible();

    if (isPhone(info)) {
        await page.getByRole('button', { name: 'Open the menu' }).click();
    }

    const nav = page.getByRole('navigation', { name: 'Panel' });
    await expect(nav.getByRole('link', { name: 'Queue' })).toHaveAttribute('aria-current', 'page');
    const labels = (await nav.locator('li a > span.truncate').allTextContents()).map((label) => label.trim());
    expect(labels).toEqual(filament);

    await nav.getByRole('link', { name: /^Customers/ }).click();
    await expect(page).toHaveURL(/\/operations\/customers$/);
});

test('the top bar’s branch scopes the queue, and goes back to all', async ({ page }) => {
    await openQueue(page, TEAM.owner);
    const trigger = page.getByRole('button', { name: /^Branch: / });
    await expect(trigger).toHaveAccessibleName('Branch: All branches');
    await expect(page.getByRole('combobox', { name: 'Branch' })).toBeVisible();

    await trigger.press('Enter');
    const options = page.getByRole('menuitemradio');
    await expect(options.first()).toBeVisible();
    const name = (await options.nth(1).innerText()).trim();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');

    await expect(trigger).toHaveAccessibleName(`Branch: ${name}`);
    await expect(column(page, 'confirmed')).toBeVisible();
    // One branch in view: nothing to pick on the page
    await expect(page.getByRole('combobox', { name: 'Branch' })).toHaveCount(0);

    await trigger.click();
    // On a phone it is a sheet that slides up: wait until it is all there
    const all = page.getByRole('menuitemradio', { name: 'All branches' });
    await expect(all).toBeInViewport({ ratio: 1 });
    await all.click();
    await expect(trigger).toHaveAccessibleName('Branch: All branches');
});

test('a write without the page’s CSRF token is refused', async ({ page }) => {
    await openQueue(page);

    const response = await page.request.post('/console/api/operations/queue/1/assigned-to', { data: { assignedTo: 'x' }, headers: { Accept: 'application/json' } });

    expect(response.status()).toBe(419);
});

test('loading, a failure with a retry, and live updates off', async ({ page }) => {
    await page.route('**/live/ticket', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Live updates are off."}' }));
    await adminLogin(page, TEAM.operations);

    let fail = true;
    await page.route('**/console/api/operations/queue*', (route) => (fail ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }) : route.continue()));
    await page.goto('/console/operations/queue');
    const alert = page.getByRole('main').getByRole('alert');
    await expect(alert).toContainText('Something went wrong on our side. Try again.');

    fail = false;
    await alert.getByRole('button', { name: 'Try again' }).click();
    await expect(column(page, 'ready')).toBeVisible();
    await expect(alert).toHaveCount(0);
});

test('the queue is accessible, light and dark, and fits 360px', async ({ page }) => {
    await openQueue(page);
    await expectAccessible(page);

    await page.getByRole('button', { name: /^Assign someone to|^Change who is on/ }).first().click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expectAccessible(page);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);

    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
});

test('review screenshots', async ({ page }, info) => {
    await openQueue(page);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `test-results/console-queue-${info.project.name}.png` });
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.screenshot({ path: `test-results/console-queue-${info.project.name}-dark.png` });
});

test('a detailer cannot reach another panel’s screen', async ({ page }) => {
    await submitLogin(page, TEAM.operations);
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));

    const response = await page.goto('/console/owner/vehicle-sizes');

    expect(response?.status()).toBe(403);
});
