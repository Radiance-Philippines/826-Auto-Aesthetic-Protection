import { expect, type Locator, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Closures. The closure here is almost a year ahead, past
 * every branch's booking window, and is deleted again.
 */
const BASE = '/console/operations/branch-blocks';

async function typeDate(page: Page, label: string, value: string) {
    const input = page.getByRole('textbox', { name: label });
    await input.fill(value);
    await input.press('Tab');
}

/** On a phone a dialog is a sheet that scrolls into place: touching it on the way would swipe it away */
async function settled(dialog: Locator) {
    let last = Number.NaN;
    await expect
        .poll(
            async () => {
                const top = (await dialog.boundingBox())?.y ?? Number.NaN;
                const still = top === last;
                last = top;

                return still;
            },
            { intervals: [250] },
        )
        .toBe(true);
}

const ahead = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);

test('a manager marks days fully booked, changes the closure and deletes it', async ({ page }, info) => {
    const why = `E2E ConClose ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.manager);
    await page.goto(BASE);

    await page.getByRole('button', { name: 'Add a closure' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Create closure' });
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog.getByText('Pick a branch.')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await dialog.getByRole('combobox', { name: 'Branch' }).click();
    // A manager cannot close every branch
    await expect(page.getByRole('option', { name: 'Every branch' })).toHaveCount(0);
    await page.getByRole('option', { name: 'Cainta – Ortigas Extension' }).click();
    await dialog.getByRole('radio', { name: 'Fully booked (no new bookings)' }).click();
    await dialog.getByRole('textbox', { name: 'Why' }).fill(why);
    await typeDate(page, 'From', ahead(320 + info.retry));
    await typeDate(page, 'To (the last day)', ahead(322 + info.retry));
    await expectAccessible(page);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog).toBeHidden();

    const row = page.getByRole('table', { name: 'Closures' }).locator('tbody tr').filter({ hasText: why });
    await expect(row).toContainText('Fully booked');
    await expect(row).toContainText('Cainta – Ortigas Extension');
    await expect(row).toContainText('–');

    await row.getByRole('button', { name: `Edit ${why}` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${why}` });
    await settled(edit);
    await edit.getByRole('radio', { name: 'Closed (no drop-offs, work pauses)' }).click();
    await edit.getByRole('textbox', { name: 'Private note' }).fill('Made by a test');
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();
    await expect(row).toContainText('Closed');
    await expect(page.locator('[data-slot=dialog-backdrop]')).toBeHidden();
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    // Past ones are a different list
    await page.getByRole('combobox', { name: 'When' }).click();
    await page.getByRole('option', { name: 'Past' }).click();
    await expect(page).toHaveURL(/when=past/);
    await expect(row).toHaveCount(0);
    await page.getByRole('combobox', { name: 'When' }).click();
    await page.getByRole('option', { name: 'Upcoming' }).click();

    await row.getByRole('button', { name: `More actions for ${why}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('alertdialog', { name: `Delete ${why}` }).getByRole('button', { name: 'Delete' }).click();
    await expect(row).toHaveCount(0);
    await expect(page.locator('[data-slot=alert-dialog-backdrop]')).toBeHidden();

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});
