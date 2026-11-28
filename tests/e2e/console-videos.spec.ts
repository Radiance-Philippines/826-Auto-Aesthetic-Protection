import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { settled } from './console-sheet';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Site → Videos. The video here is hidden from the site before long and deleted again. */
const BASE = '/console/owner/videos';

test('add a video, hide it, correct it and delete it', async ({ page }, info) => {
    const title = `E2E ConVideo ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);

    await page.getByRole('button', { name: 'Add a video' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Create video' });
    await settled(dialog);
    await dialog.getByRole('textbox', { name: 'YouTube link' }).fill('https://vimeo.com/1');
    await dialog.getByRole('textbox', { name: 'Title' }).fill(title);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog.getByText('Paste a YouTube link (youtube.com or youtu.be, starting with https://).')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);
    await expectAccessible(page);
    await dialog.getByRole('textbox', { name: 'YouTube link' }).fill('https://youtu.be/aaaaaaaaaaa');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog).toBeHidden();

    const row = page.getByRole('table', { name: 'Videos' }).locator('tbody tr').filter({ hasText: title });
    await expect(row.getByRole('link', { name: 'https://www.youtube.com/watch?v=aaaaaaaaaaa' })).toBeVisible();
    const shown = row.getByRole('switch', { name: `Shown: ${title}` });
    await expect(shown).toBeChecked();
    const saved = page.waitForResponse('**/published');
    await shown.press('Space');
    expect((await saved).status()).toBe(200);
    await page.reload();
    await expect(shown).not.toBeChecked();

    await row.getByRole('button', { name: `Edit ${title}` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${title}` });
    await settled(edit);
    await edit.getByRole('textbox', { name: 'Caption' }).fill('Made by a test');
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();

    await row.getByRole('button', { name: `More actions for ${title}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('alertdialog', { name: `Delete ${title}` }).getByRole('button', { name: 'Delete' }).click();
    await expect(row).toHaveCount(0);

    await expect(page.locator('[data-slot=alert-dialog-backdrop]')).toBeHidden();
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 360, height: 780 });
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});
