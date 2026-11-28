import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Shop → Services. The service here is not bookable, offered nowhere, and deleted again. */
const BASE = '/console/owner/services';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAACgAAAAeCAIAAADRv8uKAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAALklEQVRIie3NIREAQAgAsOclTTn6eyrgMFuBRXa9C/9kFYvFYrFYLBaLxWLxygACLQEagDMW+QAAAABJRU5ErkJggg==', 'base64');

test('add a service with a picture, lines and prices, change it, filter it and delete it', async ({ page }, info) => {
    test.setTimeout(90_000);
    const name = `E2E ConService ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);
    const table = page.getByRole('table', { name: 'Services' });
    await expect(table).toContainText('Graphene coating');
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    // Rows move from their menu; the order is only looked at here, not saved
    await page.route('**/services/reorder', (route) => route.fulfill({ status: 204 }));
    await page.getByRole('button', { name: 'More actions for Exterior detailing' }).click();
    const sent = page.waitForRequest('**/services/reorder');
    await page.getByRole('menuitem', { name: 'Move down' }).click();
    expect((await sent).postDataJSON().ids.length).toBeGreaterThan(5);
    await page.unroute('**/services/reorder');

    await page.getByRole('link', { name: 'Add a service' }).click();
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.getByText('The name field is required.')).toBeVisible();
    await expect(page.getByText('The category field is required.')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
    await page.getByRole('combobox', { name: 'Category' }).click();
    await page.getByRole('option', { name: 'Coating', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Web address' })).toHaveValue(`e2e-conservice-${info.project.name}${info.retry}`);
    await page.getByRole('switch', { name: 'Bookable' }).click();

    await page.getByRole('button', { name: 'Add a line' }).click();
    await page.keyboard.type('Wash first');
    await page.getByRole('button', { name: 'Add a line' }).click();
    await page.keyboard.type('Cure');
    await page.getByRole('button', { name: 'More actions for What’s included, line 2' }).click();
    await page.getByRole('menuitem', { name: 'Move up' }).click();
    await expect(page.getByRole('textbox', { name: 'What’s included, line 1' })).toHaveValue('Cure');
    // And from the keyboard on the drag handle
    await page.getByRole('button', { name: 'Move What’s included, line 1', exact: true }).focus();
    await page.keyboard.press('Space');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Space');
    await expect(page.getByRole('textbox', { name: 'What’s included, line 1' })).toHaveValue('Wash first');

    await page.locator('input[type=file]').setInputFiles({ name: 'work.png', mimeType: 'image/png', buffer: PNG });
    await page.getByRole('textbox', { name: 'Small', exact: true }).fill('1500');
    await page.getByRole('textbox', { name: 'Large', exact: true }).fill('2500');
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page.getByRole('heading', { level: 1, name: `Edit ${name}` })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Saved picture' })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('textbox', { name: 'Small', exact: true })).toHaveValue('1500');
    await expect(page.getByRole('textbox', { name: 'What’s included, line 2' })).toHaveValue('Cure');
    // Converted to WebP with its renditions (the throwaway server has no public/storage link to load it from)
    await expect(page.getByRole('img', { name: 'Saved picture' })).toHaveAttribute('src', /\/storage\/services\/.+-sm\.webp$/);

    await page.getByRole('button', { name: 'Remove What’s included, line 2' }).click();
    await page.getByRole('textbox', { name: 'Takes (days)' }).fill('2');
    await page.getByRole('button', { name: 'Remove picture' }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Saved', { exact: true })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Saved picture' })).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'What’s included, line 2' })).toHaveCount(0);

    await page.goto(BASE);
    await page.getByRole('combobox', { name: 'Category' }).click();
    await page.getByRole('option', { name: 'Coating', exact: true }).click();
    await expect(table).not.toContainText('Exterior detailing');
    await page.getByRole('searchbox', { name: 'Search services' }).fill(name);
    const row = table.locator('tbody tr');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('2 days');
    await expect(row).toContainText('₱1,500 – ₱2,500');

    await page.getByRole('link', { name: `Edit ${name}` }).click();
    await page.getByRole('button', { name: 'Delete', exact: true }).click();
    const question = page.getByRole('alertdialog', { name: `Delete ${name}` });
    await expect(question).toContainText('Bookings already made keep this service’s name');
    await question.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Services' })).toBeVisible();
    await expect(table).not.toContainText(name);
});

test('services are accessible dark and fit 360px', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto(BASE);
    await expect(page.getByRole('table', { name: 'Services' })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
    await page.getByRole('link', { name: 'Edit Graphene coating', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toHaveValue('Graphene coating');
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});
