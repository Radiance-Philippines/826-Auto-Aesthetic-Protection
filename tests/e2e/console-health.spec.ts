import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's System health. Reads only. */
test('what is on, check again, accessible and fits 360px', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/system/health');

    await expect(page.getByRole('heading', { level: 1, name: 'System health' })).toBeVisible();
    await expect(page.getByRole('main').getByText('Email is set up')).toBeVisible();
    await expect(page.getByRole('main').getByText('Customer app version')).toBeVisible();
    const again = page.waitForResponse('**/console/api/system/health');
    await page.getByRole('button', { name: 'Check again' }).click();
    expect((await again).status()).toBe(200);
    expect(problems()).toEqual([]);

    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-health-${info.project.name}.png` });
});
