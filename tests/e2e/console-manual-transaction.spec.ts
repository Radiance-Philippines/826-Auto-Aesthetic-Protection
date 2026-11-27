import { expect, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Manual transaction. The job is dated well in the past so no
 * other spec's day counts it, and it is deleted again.
 */
const BASE = '/console/sales/manual-transaction';

async function pick(page: Page, label: string, option: string | RegExp) {
    await page.getByRole('combobox', { name: label, exact: true }).click();
    await page.getByRole('option', { name: option }).first().click();
}

test('a job done outside the site is saved with its prices', async ({ page }, info) => {
    test.setTimeout(90_000);
    const name = `E2E Manual ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.sales);
    await page.goto(BASE);
    await expect(page.getByRole('heading', { level: 1, name: 'Manual transaction' })).toBeVisible();
    await expectNoHorizontalOverflow(page);

    // An existing customer can be found by typing
    const search = page.getByRole('combobox', { name: 'Customer', exact: true });
    await search.fill('E2E Cust');
    await page.getByRole('option', { name: /^E2E Customer/ }).click();
    await expect(search).toHaveValue(/^E2E Customer/);

    await page.getByRole('button', { name: 'Save transaction' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Not saved' })).toBeVisible();
    await expect(page.getByText('Pick at least one service.')).toBeVisible();

    await page.getByRole('switch', { name: 'New customer' }).click();
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
    await page.getByRole('textbox', { name: 'Mobile' }).fill(info.project.name === 'phone' ? '0919 333 4402' : '0919 333 4401');
    const date = page.getByRole('textbox', { name: 'Date of service' });
    await date.fill('2025-06-10');
    await date.press('Tab');
    await page.getByRole('textbox', { name: 'Year' }).fill('2019');
    await page.getByRole('textbox', { name: 'Make and model' }).fill('Ford Ranger');
    await pick(page, 'Type', 'Pickup');
    await page.getByRole('textbox', { name: 'Plate number' }).fill(`MT${info.project.name}${info.retry}`);
    await pick(page, 'Size class', /./);
    await page.getByRole('group', { name: 'Services done' }).first().locator('label').first().click();

    // The price starts from the rate and is typed over
    const price = page.getByRole('region', { name: 'Prices' }).getByRole('textbox').first();
    await expect(price).toHaveValue(/^\d+$/);
    await price.fill('3200');
    await page.getByRole('textbox', { name: 'Discount (optional)' }).fill('200');
    await page.getByRole('textbox', { name: 'Amount paid' }).fill('3000');
    await page.getByRole('button', { name: 'Save transaction' }).click();
    await expect(page.getByText('Say how it was paid.')).toBeVisible();
    await pick(page, 'Payment method', 'Cash');
    await page.keyboard.press('Control+s');

    await expect(page).toHaveURL(/\/console\/sales\/bookings\/\d+$/);
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^${name} · 826-`) })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Payment' })).toContainText('₱3,000');
    await expect(page.getByRole('region', { name: 'When and where' })).toContainText('₱3,000');
    const address = new URL(page.url()).pathname;
    // Allowed: the 422s asked for above, and a look-up dropped because the search moved on
    expect(problems().filter((problem) => !problem.includes('422') && !problem.includes('ERR_ABORTED'))).toEqual([]);

    await page.goto('about:blank');
    await page.context().clearCookies();
    await adminLogin(page, TEAM.owner);
    await page.goto(address);
    await page.getByRole('button', { name: 'More actions for this booking' }).click();
    const remove = page.getByRole('menuitem', { name: 'Delete', exact: true });
    await expect(remove).toBeInViewport();
    await remove.click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page).toHaveURL(/\/console\/sales\/bookings$/);
});

test('a detailer cannot open it', async ({ page }) => {
    await adminLogin(page, TEAM.operations);
    const response = await page.goto('/console/operations/manual-transaction');

    expect(response?.status()).toBe(403);
});

test('the form is accessible, light and dark, and fits 360px', async ({ page }, info) => {
    await adminLogin(page, TEAM.sales);
    await page.goto(BASE);
    await expect(page.getByRole('group', { name: 'Services done' }).first()).toBeVisible();
    await page.getByRole('group', { name: 'Services done' }).first().locator('label').first().click();
    await expect(page.getByRole('region', { name: 'Prices' })).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-manual-transaction-${info.project.name}.png`, fullPage: true });

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);

    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
});
