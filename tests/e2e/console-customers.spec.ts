import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Customers. The customer here is added through the screen and deleted again. */
const BASE = '/console/sales/customers';

/** A unit for the new customer, in the throwaway database tests/e2e/serve.sh made (the screen has no "add a unit") */
function giveUnit(guest: string, plate: string): void {
    const code = `\\App\\Models\\Vehicle::factory()->create(['guest_id' => ${Number(guest)}, 'year' => 2022, 'model' => 'Toyota Raize', 'type' => 'suv', 'plate_number' => '${plate}']);`;
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '' },
        stdio: 'pipe',
    });
}

test('add, edit, size a unit, find and delete a customer', async ({ page }, info) => {
    test.setTimeout(90_000);
    const name = `E2E ConCust ${info.project.name}${info.retry}`;
    const plate = `CC${info.project.name.toUpperCase()}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.sales);
    await page.goto(BASE);
    await expect(page.getByRole('table', { name: 'Customers' })).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await page.getByRole('link', { name: 'Add a customer' }).click();
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.getByText('Add the customer’s name.')).toBeVisible();
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
    await page.getByRole('textbox', { name: 'Mobile' }).fill(info.project.name === 'phone' ? '0918 222 3302' : '0918 222 3301');
    await page.getByRole('textbox', { name: 'Private note' }).fill('Prefers mornings');
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    const address = new URL(page.url()).pathname;
    await expect(page.getByRole('region', { name: 'Details' })).toContainText('Walk-in');
    await expect(page.getByRole('region', { name: 'Warranty' })).toContainText('0 services are covered now');
    await expect(page.getByText('No units')).toBeVisible();

    await page.getByRole('link', { name: 'Edit' }).click();
    await page.getByRole('combobox', { name: 'Sales rep' }).fill('Sales Ad');
    await page.getByRole('option', { name: 'Sales Admin' }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('region', { name: 'Details' })).toContainText('Sales Admin');

    await page.getByRole('button', { name: 'Redeem points' }).click();
    const redeeming = page.getByRole('dialog', { name: 'Redeem points: 0 available' });
    await expect(redeeming.getByRole('button', { name: 'Redeem' })).toBeInViewport({ ratio: 1 });
    await redeeming.getByRole('textbox', { name: 'Points' }).fill('1');
    await redeeming.getByRole('button', { name: 'Redeem' }).click();
    await expect(redeeming.getByRole('alert').first()).toContainText('They only have 0 points.');
    await redeeming.getByRole('button', { name: 'Cancel' }).click();

    giveUnit(address.split('/').pop()!, plate);
    await page.reload();
    const units = page.getByRole('table', { name: 'Units' });
    await expect(units).toContainText(plate);
    await expect(units).toContainText('Not reviewed');
    await units.getByRole('button', { name: `Set size of ${plate}` }).click();
    const sizing = page.getByRole('dialog', { name: 'Set size' });
    await expect(sizing.getByRole('button', { name: 'Set size' })).toBeInViewport({ ratio: 1 });
    await sizing.getByRole('combobox', { name: 'Size class' }).click();
    await page.getByRole('option').first().click();
    await sizing.getByRole('button', { name: 'Set size' }).click();
    await expect(sizing).toBeHidden();
    await expect(units).not.toContainText('Not reviewed');

    await units.getByRole('button', { name: `History of ${plate}` }).click();
    const history = page.getByRole('dialog', { name: `Service history: ${plate}` });
    await expect(history).toContainText('No bookings for this unit at your branches.');
    await history.getByRole('button', { name: 'Close' }).first().click();
    await expect(history).toBeHidden();

    await page.getByRole('tab', { name: 'Referral' }).click();
    await expect(page.getByText('No one has used this code yet')).toBeVisible();
    await page.getByRole('tab', { name: 'Points' }).click();
    await expect(page.getByText('No points earned or spent yet')).toBeVisible();
    await expectNoHorizontalOverflow(page);

    // Allowed: the 422s asked for above, and a look-up dropped because the search moved on
    expect(problems().filter((problem) => !problem.includes('422') && !problem.includes('ERR_ABORTED'))).toEqual([]);

    await page.goto(BASE);
    await page.getByRole('searchbox', { name: 'Search customers' }).fill(plate.toLowerCase());
    const rows = page.getByRole('table', { name: 'Customers' }).locator('tbody tr');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText(name);

    // Only a super admin deletes
    await page.goto('about:blank');
    await page.context().clearCookies();
    await adminLogin(page, TEAM.owner);
    await page.goto(address);
    await page.getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page).toHaveURL(new RegExp(`${BASE}$`));
});

test('a customer who is not there says so, and a detailer cannot add one', async ({ page }) => {
    await adminLogin(page, TEAM.operations);
    await page.goto('/console/operations/customers/999999');
    await expect(page.getByRole('main').getByRole('alert')).toContainText('That isn’t there any more.');

    await page.goto('/console/operations/customers');
    await expect(page.getByRole('table', { name: 'Customers' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Add a customer' })).toHaveCount(0);
});

test('customers are accessible, light and dark, and fit 360px', async ({ page }, info) => {
    test.setTimeout(90_000);
    await adminLogin(page, TEAM.sales);
    await page.goto(BASE);
    const table = page.getByRole('table', { name: 'Customers' });
    await expect(table).toContainText('E2E Customer');
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-customers-${info.project.name}.png` });
    await page.getByRole('button', { name: 'Filters' }).click();
    await expect(page.getByRole('combobox', { name: 'Source' })).toBeVisible();
    await expectAccessible(page);

    await table.getByRole('link', { name: 'View E2E Customer' }).click();
    await expect(page.getByRole('table', { name: 'Units' })).toContainText('CRM1234');
    const view = new URL(page.url()).pathname;
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-customers-view-${info.project.name}.png`, fullPage: true });
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);

    await page.goto(`${BASE}/create`);
    await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toBeVisible();
    await expectAccessible(page);

    // "Create & create another" empties the form for the next one (answered here: nothing is written)
    await page.route('**/console/api/sales/customers', (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 201, contentType: 'application/json', body: '{"id":1}' }) : route.fallback()));
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill('Nobody');
    await page.getByRole('button', { name: 'Create & create another' }).click();
    await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toHaveValue('');
    await expect(page).toHaveURL(new RegExp(`${BASE}/create$`));

    await page.setViewportSize({ width: 360, height: 780 });
    for (const address of [BASE, view, `${view}/edit`, `${BASE}/create`]) {
        await page.goto(address);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await expectNoHorizontalOverflow(page);
    }
});
