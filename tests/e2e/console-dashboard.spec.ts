import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's dashboard. Reads only. */
test('cards, filters kept in the address, export and a card’s link', async ({ page }) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.sales);
    await page.goto('/console/sales/dashboard');

    await expect(page.getByRole('heading', { level: 2, name: 'Needs attention' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Today' }).getByRole('link', { name: /Drop-offs today/ })).toBeVisible();

    await page.getByRole('combobox', { name: 'Period' }).click();
    await page.getByRole('option', { name: 'Custom dates' }).click();
    await expect(page).toHaveURL(/period=custom/);
    await page.getByRole('textbox', { name: 'From' }).fill('2026-11-01');
    await page.getByRole('textbox', { name: 'From' }).press('Enter');
    await expect(page).toHaveURL(/from=2026-11-01/);
    await page.reload();
    await expect(page.getByRole('textbox', { name: 'From' })).toBeVisible();

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Export' }).click()]);
    expect(download.suggestedFilename()).toMatch(/^826-daily-sales-.*\.csv$/);

    await page.getByRole('link', { name: /Needs you/ }).click();
    await expect(page).toHaveURL(/\/console\/sales\/bookings\?tab=action/);
    expect(problems().filter((problem) => !problem.includes('ERR_ABORTED'))).toEqual([]);
});

test('the sales chart: bars, a readout and the numbers as a table', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await page.route('**/console/api/owner/dashboard', async (route) => {
        const response = await route.fetch();
        const json = await response.json();
        const chart = json.widgets.find((widget: { key: string }) => widget.key === 'chart');
        Object.assign(chart, { units: 3, labels: ['Nov 1', 'Nov 2', 'Nov 3'], sales: [0, 12500, 4000] });
        await route.fulfill({ response, json });
    });
    await page.goto('/console/owner/dashboard');

    const chart = page.getByRole('figure', { name: 'Sales per day' });
    await chart.getByRole('button', { name: 'Show the numbers' }).click();
    await expect(chart.getByRole('table')).toContainText('₱12,500');
    await expectAccessible(page);
    await chart.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `test-results/console-dashboard-${info.project.name}.png` });
});

test('a failed load, accessible light and dark, fits 360px', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    let fail = true;
    await page.route('**/console/api/owner/dashboard', (route) => (fail ? route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }) : route.continue()));
    await page.goto('/console/owner/dashboard');
    await expect(page.getByRole('main').getByRole('alert')).toContainText('Something went wrong on our side.');
    fail = false;
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByRole('heading', { level: 2, name: 'Staff' })).toBeVisible();
    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-dashboard-${info.project.name}-360-dark.png` });
});
