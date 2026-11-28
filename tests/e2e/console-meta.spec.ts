import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Meta settings. Nothing about the app is saved (Messenger stays off); a new verify token is harmless here. */
test('a new token is shown once, a refusal, accessible and fits 360px', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/system/meta');

    await expect(page.getByText(/\/webhooks\/meta/)).toBeVisible();
    await page.getByRole('button', { name: 'Make a new verify token' }).click();
    const made = page.waitForResponse('**/meta/verify-token');
    await page.getByRole('alertdialog', { name: 'Make a new verify token' }).getByRole('button', { name: 'Confirm' }).click();
    const { verifyToken } = await (await made).json();
    await expect(page.getByRole('main').getByText(verifyToken)).toBeVisible();
    await page.reload();
    await expect(page.getByText('Hidden. Make a new one if you lost it.')).toBeVisible();

    const appId = page.getByRole('textbox', { name: 'App ID' });
    await appId.fill('abc');
    await appId.press('Control+s');
    await expect(page.getByText('The App ID is digits only.')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-meta-${info.project.name}.png`, fullPage: true });
});
