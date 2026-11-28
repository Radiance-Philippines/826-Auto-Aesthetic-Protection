import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { settled } from './console-sheet';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Site → Photos. The two photos uploaded here are not featured and are deleted again. */
const BASE = '/console/owner/photos';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAACgAAAAeCAIAAADRv8uKAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAALklEQVRIie3NIREAQAgAsOclTTn6eyrgMFuBRXa9C/9kFYvFYrFYLBaLxWLxygACLQEagDMW+QAAAABJRU5ErkJggg==', 'base64');

test('upload two photos, change them one by one and together, and delete them', async ({ page }, info) => {
    test.setTimeout(90_000);
    const caption = `E2E ConPhoto ${info.project.name}${info.retry}`;
    const problems = watchProblems(page);
    // The throwaway server has no public/storage link: the thumbnails are not what is tested
    await page.route('**/storage/**', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: PNG }));
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);

    await page.getByRole('button', { name: 'Upload photos' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Upload photos' });
    await settled(dialog);
    await dialog.getByRole('button', { name: 'Add photos' }).click();
    await expect(dialog.getByText('Choose at least one picture.')).toBeVisible();
    await expect(dialog.getByText('Pick a category.')).toBeVisible();
    await dialog.locator('input[type=file]').setInputFiles([
        { name: 'one.png', mimeType: 'image/png', buffer: PNG },
        { name: 'two.png', mimeType: 'image/png', buffer: PNG },
    ]);
    await dialog.getByRole('combobox', { name: 'Category' }).click();
    await page.getByRole('option', { name: 'Window tint' }).click();
    await expectAccessible(page);
    await dialog.getByRole('button', { name: 'Add photos' }).click();
    await expect(page.getByText('2 photos added')).toBeVisible();
    await expect(dialog).toBeHidden();

    const rows = page.getByRole('table', { name: 'Photos' }).locator('tbody tr');
    await page.getByRole('combobox', { name: 'Category' }).click();
    await page.getByRole('option', { name: 'Window tint' }).click();
    // Only this test's two are in the category (the shop starts with no photos of it)
    await expect(rows).toHaveCount(2);

    // Edit the first: caption and category
    await rows.first().getByRole('link', { name: /^Edit / }).click();
    await page.getByRole('textbox', { name: 'Caption' }).fill(caption);
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Photos' })).toBeVisible();
    const row = rows.filter({ hasText: caption });
    await expect(row).toContainText('Window tint');

    const saved = page.waitForResponse('**/featured');
    await row.getByRole('switch', { name: `Featured: ${caption}` }).press('Space');
    expect((await saved).status()).toBe(200);
    await page.reload();
    await expect(row.getByRole('switch', { name: `Featured: ${caption}` })).toBeChecked();

    // Together
    await page.getByRole('combobox', { name: 'Category' }).click();
    await page.getByRole('option', { name: 'Window tint' }).click();
    // The label is the tap target (UI SDK stretches it over the box)
    await page.getByText('Select all on this page').click();
    await expect(page.getByText('2 selected')).toBeVisible();
    await page.getByRole('button', { name: 'Remove from Home' }).click();
    await expect(row.getByRole('switch', { name: `Featured: ${caption}` })).not.toBeChecked();
    // The label is the tap target (UI SDK stretches it over the box)
    await page.getByText('Select all on this page').click();
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Delete selected' }).click();
    await page.getByRole('alertdialog', { name: 'Delete selected photos' }).getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText(caption)).toHaveCount(0);
    expect(problems()).toEqual([]);

    await expect(page.locator('[data-slot=alert-dialog-backdrop]')).toBeHidden();
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 360, height: 780 });
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});
