import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { expect, type Page, type TestInfo, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Bookings. Every booking here is made through the screen
 * itself, on days far ahead or as home requests, and deleted again.
 */
const BASE = '/console/operations/bookings';

/** A home request of this test's own, in the throwaway database tests/e2e/serve.sh made (no seeded service can be done at home) */
function homeRequest(name: string): number {
    const code = `\\App\\Models\\Booking::query()->where('name', '${name}')->get()->each->delete(); echo \\App\\Models\\Booking::factory()->create(['branch_id' => \\App\\Models\\Branch::query()->where('slug', 'cainta')->value('id'), 'name' => '${name}', 'kind' => 'home', 'status' => 'requested', 'drop_off_on' => null, 'ready_on' => null, 'preferred_date' => today()->addWeeks(10)->toDateString(), 'address' => '12 Sampaguita St, Cainta', 'total_days' => 1])->id;`;
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '', MAIL_MAILER: 'array', MAIL_USE_MAILBOX_SETTINGS: 'false' },
        stdio: 'pipe',
    });

    return Number(out.toString().trim().split(/\s+/).pop());
}

/** A weekday (never Sunday) so many weeks ahead, as YYYY-MM-DD */
function ahead(weeks: number, weekday: number): string {
    const date = new Date();
    date.setDate(date.getDate() + weeks * 7);
    date.setDate(date.getDate() + ((weekday - date.getDay() + 7) % 7));

    return date.toLocaleDateString('en-CA');
}

const dialog = (page: Page, name: string | RegExp) => page.getByRole('dialog', { name });

async function pick(page: Page, label: string, option: string | RegExp) {
    await page.getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option }).click();
}

async function typeDate(page: Page, label: string, value: string) {
    const input = page.getByRole('textbox', { name: label });
    await input.fill(value);
    await input.press('Tab');
}

/** Fill "Add a booking" and send it; returns on the new booking's page */
async function addBooking(page: Page, name: string, plate: string, date: string) {
    await page.goto(`${BASE}/create`);
    await expect(page.getByRole('heading', { level: 1, name: 'Add a booking' })).toBeVisible();
    // Pressing the words ticks the box, as a finger does
    await page.getByRole('group', { name: 'Services' }).locator('label').first().click();
    await expect(page.getByRole('group', { name: 'Services' }).getByRole('checkbox').first()).toBeChecked();
    await typeDate(page, 'Drop-off date', date);
    await page.getByRole('textbox', { name: 'Year' }).fill('2021');
    await page.getByRole('textbox', { name: 'Make and model' }).fill('Toyota Vios');
    await pick(page, 'Type', 'Sedan');
    await page.getByRole('textbox', { name: 'Plate number' }).fill(plate);
    await page.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
    await page.getByRole('textbox', { name: 'Mobile' }).fill(`0917 555 ${plate.replace(/\D/g, '').padStart(4, '0').slice(-4)}`);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^${name} · 826-`) })).toBeVisible();
}

async function more(page: Page, item: string) {
    await page.getByRole('button', { name: 'More actions for this booking' }).click();
    const entry = page.getByRole('menuitem', { name: item, exact: true });
    // On a phone the menu is a sheet that slides up
    await expect(entry).toBeInViewport();
    await entry.click();
}

/** Only a super admin deletes */
async function removeAs(page: Page, address: string) {
    // Leave the page first: a request still in flight would set the old session cookie again
    await page.goto('about:blank');
    await page.context().clearCookies();
    await adminLogin(page, TEAM.owner);
    await page.goto(address.replace('/console/operations/', '/console/sales/'));
    await page.getByRole('button', { name: 'More actions for this booking' }).click();
    const entry = page.getByRole('menuitem', { name: 'Delete', exact: true });
    await expect(entry).toBeInViewport();
    await entry.click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page).toHaveURL(/\/console\/sales\/bookings$/);
}

const stamp = (info: TestInfo) => `${info.project.name}${info.retry}`;

test('add a booking, size it, move it, cancel it, find it and delete it', async ({ page }, info) => {
    test.setTimeout(120_000);
    const name = `E2E ConBook ${stamp(info)}`;
    const week = info.project.name === 'phone' ? 8 : 7;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.manager);

    // The server says what is missing before anything is saved
    await page.goto(`${BASE}/create`);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Not booked' }).first()).toBeVisible();
    await expect(page.getByText('Pick at least one service.')).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toHaveAttribute('aria-invalid', 'true');

    await addBooking(page, name, `CB${week}01`, ahead(week, 2));
    const address = new URL(page.url()).pathname;
    await expect(page.getByRole('region', { name: 'Customer' })).toContainText('Booked');
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'Review unit' }).click();
    const sizing = dialog(page, /^Review 2021 Toyota Vios/);
    // On a phone the dialog is a sheet that slides up: wait until it is all there
    await expect(sizing.getByRole('button', { name: 'Set size' })).toBeInViewport({ ratio: 1 });
    await sizing.getByRole('combobox', { name: 'Size class' }).click();
    await page.getByRole('option').first().click();
    await sizing.getByRole('button', { name: 'Set size' }).click();
    await expect(sizing).toBeHidden();
    await expect(page.getByRole('button', { name: 'Change size' })).toBeVisible();

    await more(page, 'Move');
    const moving = dialog(page, 'Move');
    const moved = ahead(week, 4);
    await typeDate(page, 'New drop-off date', moved);
    await moving.getByRole('button', { name: 'Move' }).click();
    await expect(moving).toBeHidden();
    await expect(page.getByText(/^Moved: /)).toBeVisible();

    await more(page, 'Change ready date');
    const readying = dialog(page, 'Change ready date');
    await typeDate(page, 'Ready around', ahead(week, 5));
    await readying.getByRole('button', { name: 'Submit' }).click();
    await expect(readying).toBeHidden();
    await expect(page.getByRole('region', { name: 'History' })).toContainText('Ready date');

    await page.getByRole('button', { name: 'Assigned to' }).click();
    const assigning = dialog(page, 'Who is on this unit?');
    await assigning.getByRole('textbox', { name: 'Assigned to' }).fill('Jun, Ben');
    await assigning.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('region', { name: 'When and where' })).toContainText('Jun, Ben');

    await more(page, 'Copy booking link');
    const link = dialog(page, 'Booking link');
    await expect(link.getByRole('textbox', { name: 'Link' })).toHaveValue(/\/bookings\/\d+\?key=/);
    await link.getByRole('button', { name: 'Done' }).click();

    await more(page, 'Edit details');
    await expect(page).toHaveURL(new RegExp(`${address}/edit$`));
    await page.getByRole('textbox', { name: 'Private note' }).fill('Window seat');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page).toHaveURL(new RegExp(`${address}$`));
    await expect(page.getByRole('region', { name: 'Customer' })).toContainText('Window seat');

    await more(page, 'Cancel booking');
    const cancelling = dialog(page, 'Cancel booking');
    await cancelling.getByRole('textbox', { name: 'Reason (the customer sees this)' }).fill('Test run');
    await cancelling.getByRole('button', { name: 'Cancel booking' }).click();
    await expect(cancelling).toBeHidden();
    await expect(page.getByRole('region', { name: 'Customer' })).toContainText('Cancelled');
    await expect(page.getByRole('button', { name: 'Assigned to' })).toHaveCount(0);

    await more(page, 'Mark as unread');
    await expect(page).toHaveURL(new RegExp(`${BASE}$`));

    // Allowed: the 422 asked for above, and a look-up dropped because the date or the dialog moved on
    expect(problems().filter((problem) => !problem.includes('422') && !problem.includes('ERR_ABORTED'))).toEqual([]);

    await page.getByRole('tab', { name: 'Cancelled' }).click();
    await expect(page).toHaveURL(/tab=cancelled/);
    await page.getByRole('searchbox', { name: 'Search bookings' }).fill(name);
    const rows = page.getByRole('table', { name: 'Bookings' }).locator('tbody tr');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Unread');
    await rows.first().getByRole('checkbox', { name: new RegExp(`^Select ${name}`) }).click();
    await page.getByRole('region', { name: 'Bulk actions' }).getByRole('button', { name: 'Mark as read' }).click();
    await expect(rows.first()).not.toContainText('Unread');

    await page.getByRole('button', { name: 'Filters' }).click();
    await page.getByRole('group', { name: 'Status' }).first().locator('label').filter({ hasText: /^Declined$/ }).click();
    await expect(page.getByText(`Nothing matches “${name}”.`)).toBeVisible();
    await page.getByRole('button', { name: 'Reset' }).click();
    await expect(rows).toHaveCount(1);

    await removeAs(page, address);
});

test('a home request is scheduled, worked and finished', async ({ page }, info) => {
    test.setTimeout(90_000);
    const name = `E2E ConHome ${stamp(info)}`;
    const address = `${BASE}/${homeRequest(name)}`;
    await adminLogin(page, TEAM.manager);

    // The create form offers a home visit its own fields; no seeded service can be done at home
    await page.goto(`${BASE}/create`);
    await page.getByRole('radio', { name: 'Home service' }).click();
    await expect(page.getByRole('textbox', { name: 'Address' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Team comes on (optional)' })).toBeVisible();
    await expect(page.getByRole('radiogroup', { name: 'Water hose' })).toBeVisible();

    await page.goto(address);
    await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^${name} · 826-`) })).toBeVisible();

    await page.getByRole('button', { name: 'Schedule' }).click();
    const scheduling = dialog(page, 'Schedule');
    await typeDate(page, 'Team comes on', ahead(10, 3));
    await scheduling.getByRole('button', { name: 'Schedule' }).click();
    await expect(scheduling).toBeHidden();

    const question = page.getByRole('alertdialog');
    await page.getByRole('button', { name: 'In progress' }).click();
    await expect(question).toContainText('The team is at the customer’s place and has started.');
    await question.getByRole('button', { name: 'Cancel' }).click();
    await expect(question).toBeHidden();
    await page.getByRole('button', { name: 'In progress' }).click();
    await question.getByRole('button', { name: 'Confirm' }).click();
    await page.getByRole('button', { name: 'Done', exact: true }).click();
    await question.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByRole('region', { name: 'Customer' })).toContainText('Exited');

    await removeAs(page, address);
});

test('a detailer sees no sales actions and no add button', async ({ page }) => {
    await adminLogin(page, TEAM.operations);
    await page.goto(BASE);
    await expect(page.getByRole('heading', { level: 1, name: 'Bookings' })).toBeVisible();
    await page.getByRole('tab', { name: 'All' }).click();
    await expect(page.getByRole('link', { name: 'Add a booking' })).toHaveCount(0);

    await page.getByRole('table', { name: 'Bookings' }).getByRole('link', { name: /^View / }).first().click();
    await expect(page.getByRole('region', { name: 'When and where' })).toBeVisible();
    await expect(page.getByRole('button', { name: /^(Review unit|Change size|Mark fee paid)$/ })).toHaveCount(0);

    await page.goto(`${BASE}/create`);
    await expect(page.getByRole('main')).toContainText('Your role doesn’t include adding bookings.');
});

test('a booking that is not theirs or not there says so', async ({ page }) => {
    await adminLogin(page, TEAM.manager);
    await page.goto(`${BASE}/999999`);

    await expect(page.getByRole('main').getByRole('alert')).toContainText('That isn’t there any more.');
    await expect(page.getByRole('link', { name: 'Back to bookings' })).toBeVisible();
});

test('bookings are accessible, light and dark, and fit 360px', async ({ page }, info) => {
    test.setTimeout(90_000);
    await adminLogin(page, TEAM.manager);
    await page.goto(`${BASE}?tab=all`);
    const table = page.getByRole('table', { name: 'Bookings' });
    await expect(table).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-bookings-${info.project.name}.png` });

    await page.getByRole('button', { name: 'Filters' }).click();
    await expect(page.getByRole('group', { name: 'Status' }).first()).toBeVisible();
    await expectAccessible(page);

    await table.getByRole('link', { name: /^View / }).first().click();
    await expect(page.getByRole('region', { name: 'When and where' })).toBeVisible();
    const view = new URL(page.url()).pathname;
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-bookings-view-${info.project.name}.png`, fullPage: true });

    await page.goto(`${BASE}/create`);
    await expect(page.getByRole('group', { name: 'Services' })).toBeVisible();
    await expectAccessible(page);

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-bookings-create-${info.project.name}-dark.png`, fullPage: true });

    await page.setViewportSize({ width: 360, height: 780 });
    for (const address of [`${BASE}/create`, view, `${view}/edit`, `${BASE}?tab=all`]) {
        await page.goto(address);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await expectNoHorizontalOverflow(page);
    }

    await expectAccessible(page);
});
