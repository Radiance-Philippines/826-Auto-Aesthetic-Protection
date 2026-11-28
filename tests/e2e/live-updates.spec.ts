import { type Browser, type BrowserContext, expect, type Page, test, type TestInfo } from '@playwright/test';

import { adminLogin, adminOpen, main, TEAM, unit } from './admin-support';
import { signIn } from './support';

/**
 * Live updates over Radiance Flow. The app talks to a stand-in for Flow (tests/e2e/fake-flow.mjs): this suite checks that
 * a change made in one browser reaches another without a reload, through the real ticket, the real socket frames and the
 * real publishing code, and that every screen still works when the socket is not there.
 */

const flow = `http://127.0.0.1:${process.env.E2E_FLOW_PORT}`;

type FlowState = { published: number; subscribes: number; refused: number; sockets: number; topics: string[] };

const flowState = async (): Promise<FlowState> => (await fetch(`${flow}/_test/state`)).json();

/** A second, separate browser (its own cookies), set up like the project's own */
async function another(browser: Browser, info: TestInfo): Promise<BrowserContext> {
    return browser.newContext({ baseURL: info.project.use.baseURL, viewport: info.project.use.viewport, locale: 'en-PH', timezoneId: 'Asia/Manila', reducedMotion: 'reduce' });
}

/** The pages have their sockets open and subscribed (the count stops moving) */
async function waitForSockets(count: number) {
    await expect
        .poll(async () => (await flowState()).sockets, { timeout: 15_000 })
        .toBeGreaterThanOrEqual(count);

    let last = -1;
    await expect
        .poll(
            async () => {
                const now = (await flowState()).subscribes;
                const settled = now === last;
                last = now;

                return settled;
            },
            { timeout: 15_000, intervals: [400] },
        )
        .toBe(true);
}

const column = (page: Page, status: string) => page.locator(`section[aria-labelledby="queue-${status}"]`);

/** Marks the page, so a reload would be noticed */
const mark = (page: Page) => page.evaluate(() => ((window as unknown as { __liveMark: string }).__liveMark = 'same page'));
const marked = (page: Page) => page.evaluate(() => (window as unknown as { __liveMark?: string }).__liveMark);

test.describe('live updates (Radiance Flow)', () => {
    test('a queue move in one browser shows in another without a reload', async ({ page, browser }, info) => {
        const name = unit(info, 'Live');
        page.on('dialog', (dialog) => dialog.accept());

        const other = await another(browser, info);
        const watcher = await other.newPage();

        try {
            await adminLogin(watcher, TEAM.manager);
            await adminOpen(watcher, '/admin/queue');
            await expect(column(watcher, 'confirmed').getByText(name)).toBeVisible();
            await mark(watcher);

            await adminLogin(page, TEAM.operations);
            await adminOpen(page, '/admin/queue');
            await waitForSockets(2);

            await column(page, 'confirmed').locator('article').filter({ hasText: name }).getByRole('button', { name: 'Arrived' }).click();
            await expect(column(page, 'arrived').getByText(name)).toBeVisible();

            await expect(column(watcher, 'arrived').getByText(name)).toBeVisible();
            await expect(column(watcher, 'confirmed').getByText(name)).toHaveCount(0);
            expect(await marked(watcher)).toBe('same page');

            expect((await flowState()).refused).toBe(0);
        } finally {
            await other.close();
        }
    });

    test('the bookings list and the bell refresh when a booking changes', async ({ page, browser }, info) => {
        const name = unit(info, 'LiveList');
        page.on('dialog', (dialog) => dialog.accept());

        const other = await another(browser, info);
        const watcher = await other.newPage();

        try {
            await adminLogin(watcher, TEAM.sales);
            await adminOpen(watcher, '/admin/bookings?tab=all');
            await watcher.getByPlaceholder('Search').first().fill(name);
            await expect(watcher.locator('table tbody tr').filter({ hasText: name })).toContainText('Booked');
            await mark(watcher);

            await adminLogin(page, TEAM.operations);
            await adminOpen(page, '/admin/queue');
            await waitForSockets(2);
            await column(page, 'confirmed').locator('article').filter({ hasText: name }).getByRole('button', { name: 'Arrived' }).click();
            await expect(column(page, 'arrived').getByText(name)).toBeVisible();

            await expect(watcher.locator('table tbody tr').filter({ hasText: name })).toContainText('Arrived');
            expect(await marked(watcher)).toBe('same page');
        } finally {
            await other.close();
        }
    });

    test('a customer sees their booking move on the site', async ({ page, browser }, info) => {
        const mine = `MIN${info.project.name.toUpperCase()}`;
        page.on('dialog', (dialog) => dialog.accept());

        const other = await another(browser, info);
        const customer = await other.newPage();

        try {
            await signIn(customer, '/bookings');
            const card = main(customer).locator('article').filter({ hasText: mine });
            await expect(card).toBeVisible();
            await expect(card).not.toContainText('Arrived');
            await mark(customer);

            await adminLogin(page, TEAM.operations);
            await adminOpen(page, '/admin/queue');
            await waitForSockets(2);
            await column(page, 'confirmed').locator('article').filter({ hasText: unit(info, 'Mine') }).getByRole('button', { name: 'Arrived' }).click();
            await expect(column(page, 'arrived').getByText(unit(info, 'Mine'))).toBeVisible();

            await expect(card).toContainText('Arrived');
            expect(await marked(customer)).toBe('same page');
            expect((await flowState()).refused).toBe(0);
        } finally {
            await other.close();
        }
    });

    test('the socket comes back by itself after a drop', async ({ page, browser }, info) => {
        const name = unit(info, 'LiveDrop');
        page.on('dialog', (dialog) => dialog.accept());

        const other = await another(browser, info);
        const watcher = await other.newPage();

        try {
            await adminLogin(watcher, TEAM.manager);
            await adminOpen(watcher, '/admin/queue');
            await adminLogin(page, TEAM.operations);
            await adminOpen(page, '/admin/queue');
            await waitForSockets(2);

            await fetch(`${flow}/_test/drop`, { method: 'POST' });
            await expect.poll(async () => (await flowState()).sockets, { timeout: 5_000 }).toBe(0);
            await waitForSockets(2);

            await column(page, 'confirmed').locator('article').filter({ hasText: name }).getByRole('button', { name: 'Arrived' }).click();
            await expect(column(watcher, 'arrived').getByText(name)).toBeVisible();
        } finally {
            await other.close();
        }
    });

    test('with no ticket (live updates off) the admin queue still works and nothing breaks', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('dialog', (dialog) => dialog.accept());
        await page.route('**/live/ticket', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Live updates are off."}' }));

        await adminLogin(page, TEAM.operations);
        await adminOpen(page, '/admin/queue');
        await expect(column(page, 'confirmed')).toBeVisible();

        await page.reload();
        await expect(column(page, 'ready')).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('with a socket that cannot be reached the site pages stay usable', async ({ page }, info) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('**/live/ticket', (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ticket: 'x.y', topics: ['826.customer.1'], expiresIn: 300, url: 'ws://127.0.0.1:1/ws' }) }),
        );

        await signIn(page, '/bookings');
        await expect(main(page).locator('article').filter({ hasText: `MIN${info.project.name.toUpperCase()}` })).toBeVisible();
        await page.waitForTimeout(1500);
        await page.reload();
        await expect(main(page).locator('h1').first()).toBeVisible();
        expect(errors).toEqual([]);
    });
});
