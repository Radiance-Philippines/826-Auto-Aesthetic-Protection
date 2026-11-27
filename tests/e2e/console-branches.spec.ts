import { expect, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Shop → Branches. The branch here is a draft added through the screen and deleted again. */
const BASE = '/console/owner/branches';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhWpMIQAAAABJRU5ErkJggg==', 'base64');

const tab = (page: Page, name: string) => page.getByRole('tab', { name: new RegExp(`^${name}`) });

async function typeTime(page: Page, day: string, field: string, keys: string) {
    await page.getByRole('group', { name: day, exact: true }).getByRole('group', { name: field, exact: true }).getByRole('spinbutton').first().click();
    await page.keyboard.type(keys);
}

test('add a branch, fill every tab, save, check the notices and delete it', async ({ page }, info) => {
    test.setTimeout(90_000);
    const name = `E2E ConBranch ${info.project.name}${info.retry}`;
    const slug = `e2e-conbranch-${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);
    await expect(page.getByRole('table', { name: 'Branches' })).toContainText('Cainta – Ortigas Extension');
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    await page.getByRole('link', { name: 'Add a branch' }).click();
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.getByText('The name field is required.')).toBeVisible();
    // The server's 422 above is the only failed request allowed
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
    await page.getByRole('textbox', { name: 'City' }).click();
    await expect(page.getByRole('textbox', { name: 'Web address' })).toHaveValue(slug);
    await expectAccessible(page);

    await tab(page, 'Days and cap').click();
    await expect(page).toHaveURL(/tab=days/);
    await page.getByRole('textbox', { name: 'Units per day (daily cap)' }).fill('3');
    await page.getByRole('group', { name: 'Monday', exact: true }).getByRole('switch', { name: 'Open' }).click();
    await typeTime(page, 'Monday', 'Opens', '0830a');
    await typeTime(page, 'Monday', 'Closes', '0600p');
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    await tab(page, 'Services').click();
    await page.getByRole('button', { name: 'Select all', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: 'Graphene coating', exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Deselect all' }).click();
    // The label is the tap target (UI SDK stretches it over the box)
    await page.locator('label').filter({ hasText: /^Graphene coating$/ }).click();
    await expect(page.getByRole('checkbox', { name: 'Graphene coating', exact: true })).toBeChecked();

    await tab(page, 'Home service').click();
    await page.getByRole('switch', { name: 'Takes home-service requests' }).click();
    const areas = page.getByRole('textbox', { name: 'Areas covered' });
    await areas.fill('Antipolo');
    await areas.press('Enter');
    await areas.fill('Taytay');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await page.getByRole('button', { name: 'Remove Taytay' }).click();
    await expect(page.getByRole('list', { name: 'Areas covered: added' }).getByRole('listitem')).toHaveText(['Antipolo']);

    await tab(page, 'Payments').click();
    const gcash = page.getByRole('region', { name: 'GCash' });
    await gcash.locator('input[type=file]').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('no') });
    await expect(gcash.getByText('notes.txt is not a JPG, PNG or WebP picture.')).toBeVisible();
    await gcash.locator('input[type=file]').setInputFiles({ name: 'qr.png', mimeType: 'image/png', buffer: PNG });
    await expect(gcash.getByRole('list', { name: 'Chosen: gcash qr code' })).toContainText('qr.png');
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    await tab(page, 'Staff').click();
    await expect(page.getByRole('checkbox', { name: /manager@826auto\.test/ })).not.toBeChecked();

    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page).toHaveURL(/\/branches\/\d+\/edit/);
    await expect(page.getByRole('heading', { level: 1, name: `Edit ${name}` })).toBeVisible();

    // What was saved comes back after a reload
    await page.reload();
    await tab(page, 'Days and cap').click();
    const closes = page.getByRole('group', { name: 'Monday', exact: true }).getByRole('group', { name: 'Closes', exact: true });
    await expect(closes).toMatchAriaSnapshot(`
        - group "Closes":
          - spinbutton "hour": "06"
          - spinbutton "minute": "00"
          - spinbutton "AM/PM": PM
    `);
    await tab(page, 'Payments').click();
    await expect(gcash.getByRole('img', { name: 'Saved gcash qr code' })).toBeVisible();
    await gcash.getByRole('button', { name: 'Remove gcash qr code' }).click();
    await expect(gcash.getByRole('img')).toHaveCount(0);

    await tab(page, 'Facebook page').click();
    await page.getByRole('button', { name: 'Connect check' }).click();
    await expect(page.getByRole('main').getByRole('alert')).toContainText('Enter the Facebook Page ID first.');
    await expectAccessible(page);

    await tab(page, 'Info').click();
    await page.getByRole('combobox', { name: 'Status' }).click();
    await page.getByRole('option', { name: 'Open for booking' }).click();
    await tab(page, 'Days and cap').click();
    await page.getByRole('textbox', { name: 'Units per day (daily cap)' }).fill('0');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Saved, but not taking online bookings yet')).toBeVisible();
    await expect(page.getByRole('link', { name: 'View on the site' })).toBeVisible();

    // A draft again, so the site never lists it
    await tab(page, 'Info').click();
    await page.getByRole('combobox', { name: 'Status' }).click();
    await page.getByRole('option', { name: 'Draft (hidden)' }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('link', { name: 'View on the site' })).toHaveCount(0);

    await page.getByRole('link', { name: 'Branches', exact: true }).first().click();
    await page.getByRole('searchbox', { name: 'Search branches' }).fill(name);
    await expect(page.getByRole('table', { name: 'Branches' }).locator('tbody tr')).toHaveCount(1);
    await page.getByRole('link', { name: `Edit ${name}` }).click();
    await page.getByRole('button', { name: 'Delete', exact: true }).click();
    const question = page.getByRole('alertdialog', { name: `Delete ${name}` });
    await question.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Branches' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Branches' })).not.toContainText(name);
});

test('the list is accessible dark and at 360px', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto(BASE);
    await expect(page.getByRole('table', { name: 'Branches' })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});

test('review screenshots', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);
    await page.getByRole('link', { name: 'Edit Cainta – Ortigas Extension' }).click();
    await tab(page, 'Days and cap').click();
    await expect(page.getByRole('group', { name: 'Monday', exact: true })).toBeVisible();
    await page.screenshot({ path: `test-results/console-branches-${info.project.name}.png` });
    await page.emulateMedia({ colorScheme: 'dark' });
    await tab(page, 'Payments').click();
    await page.screenshot({ path: `test-results/console-branches-${info.project.name}-dark.png` });
});
