import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { settled } from './console-sheet';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Shop → Backjob types. Every type made here is deleted again. */
const BASE = '/console/owner/backjob-types';

test('add two, create another, correct and delete a backjob type', async ({ page }, info) => {
    const name = `E2E ConType ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);

    await page.getByRole('button', { name: 'Add a backjob type' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Create backjob type' });
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog.getByText('The name field is required.')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);
    await expectAccessible(page);

    await dialog.getByRole('textbox', { name: 'Name' }).fill(`${name} A`);
    await dialog.getByRole('button', { name: 'Create & create another' }).click();
    await expect(dialog.getByRole('textbox', { name: 'Name' })).toHaveValue('');
    await dialog.getByRole('textbox', { name: 'Name' }).fill(`${name} B`);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog).toBeHidden();

    const rows = page.getByRole('table', { name: 'Backjob types' }).locator('tbody tr');
    await expect(rows.filter({ hasText: name })).toHaveCount(2);

    const row = rows.filter({ hasText: `${name} A` });
    await row.getByRole('button', { name: `Edit ${name} A` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${name} A` });
    await settled(edit);
    await edit.getByRole('switch', { name: 'In use' }).click();
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();
    await expect(row).toContainText('No');

    for (const letter of ['A', 'B']) {
        await rows.filter({ hasText: `${name} ${letter}` }).getByRole('button', { name: `More actions for ${name} ${letter}` }).click();
        await page.getByRole('menuitem', { name: 'Delete' }).click();
        await page.getByRole('alertdialog', { name: `Delete ${name} ${letter}` }).getByRole('button', { name: 'Delete' }).click();
        await expect(rows.filter({ hasText: `${name} ${letter}` })).toHaveCount(0);
    }

    await expect(page.locator('[data-slot=alert-dialog-backdrop]')).toBeHidden();
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 360, height: 780 });
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});
