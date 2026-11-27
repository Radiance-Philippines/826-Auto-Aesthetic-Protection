import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Shop → Categories. The category here has no services and is deleted again. */
const BASE = '/console/owner/service-categories';

test('add, correct and delete a category; one with services cannot be deleted', async ({ page }, info) => {
    const name = `E2E ConCat ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);
    const table = page.getByRole('table', { name: 'Categories' });
    await expect(table).toContainText('Coating');

    await page.getByRole('button', { name: 'More actions for Coating' }).click();
    await expect(page.getByRole('menuitem', { name: 'Delete' })).toHaveCount(0);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Add a category' }).click();
    const dialog = page.getByRole('dialog', { name: 'Create category' });
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog.getByText('The name field is required.')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await dialog.getByRole('textbox', { name: 'Name' }).fill(name);
    await dialog.getByRole('textbox', { name: 'Description' }).fill('Made by a test');
    await expect(dialog.getByRole('textbox', { name: 'Slug' })).toHaveValue(`e2e-concat-${info.project.name}${info.retry}`);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog).toBeHidden();
    const row = table.locator('tbody tr').filter({ hasText: name });
    await expect(row).toContainText('Yes');

    await row.getByRole('button', { name: `Edit ${name}` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${name}` });
    await edit.getByRole('switch', { name: 'Shown on the site' }).click();
    await expectAccessible(page);
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();
    await expect(row).toContainText('No');
    // The sheet's backdrop is still fading out for a moment; axe would read the rows through it
    await expect(page.locator('[data-slot=dialog-backdrop]')).toBeHidden();
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    await row.getByRole('button', { name: `More actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('alertdialog', { name: `Delete ${name}` }).getByRole('button', { name: 'Delete' }).click();
    await expect(row).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Add a category' })).toBeFocused();

    await page.emulateMedia({ colorScheme: 'dark' });
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});
