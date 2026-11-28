import { expect, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's App releases. Drafts only (never offered for download), and deleted again. */
const rows = (page: Page) => page.getByRole('table', { name: 'App releases' }).locator('tbody tr');
/** An empty zip, which is what an APK looks like from outside */
const apk = { name: 'build.apk', mimeType: 'application/vnd.android.package-archive', buffer: Buffer.concat([Buffer.from([0x50, 0x4b, 0x05, 0x06]), Buffer.alloc(18)]) };

test('upload, edit and delete a release', async ({ page }, info) => {
    const version = `0.0.${info.project.name === 'phone' ? 2 : 1}${info.retry}`;
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/system/app-releases');

    await page.getByRole('button', { name: 'New release' }).first().click();
    const form = page.getByRole('dialog', { name: 'New release' });
    await form.getByRole('textbox', { name: 'Version', exact: true }).fill('1.0');
    await form.getByRole('button', { name: 'Create' }).click();
    await expect(form.getByText('Write the version like 0.1.0.')).toBeVisible();
    await expect(form.getByText('Choose the APK file.')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await form.getByRole('textbox', { name: 'Version', exact: true }).fill(version);
    await form.getByRole('textbox', { name: 'Version code' }).fill(version.replace(/\D/g, '') + '9');
    const chooser = page.waitForEvent('filechooser');
    await form.getByRole('button', { name: 'Choose the APK' }).click();
    await (await chooser).setFiles(apk);
    await expect(form.getByText('build.apk')).toBeVisible();
    await expectAccessible(page);
    await form.getByRole('button', { name: 'Create' }).click();
    await expect(form).toBeHidden();
    const row = rows(page).filter({ hasText: version });
    await expect(row).toContainText('Customer app');

    await row.getByRole('button', { name: `Edit Customer app ${version}` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${version}` });
    await edit.getByRole('textbox', { name: 'Notes' }).fill('Console test build');
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();

    await row.getByRole('button', { name: `Delete Customer app ${version}` }).click();
    await page.getByRole('alertdialog', { name: `Delete ${version}` }).getByRole('button', { name: 'Delete' }).click();
    await expect(row).toHaveCount(0);
});

test('app releases: empty, accessible light and dark, fits 360px', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await page.route('**/console/api/system/app-releases', (route) => route.fulfill({ json: { data: [], apps: [] } }));
    await page.goto('/console/system/app-releases');
    await expect(page.getByText('No app releases yet')).toBeVisible();
    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-app-releases-${info.project.name}.png` });
});
