import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Security. Saved as it was found: the other specs sign in without two-factor. */
test('a save, the failed state, accessible and fits 360px', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/system/security');

    const require = page.getByRole('switch', { name: 'Require two-factor sign-in for everyone' });
    await expect(require).not.toBeChecked();
    await expect(page.getByRole('switch', { name: 'Allow passkey sign-in' })).toBeChecked();
    await page.getByRole('button', { name: 'Save security' }).click();
    await expect(page.getByText('Security saved').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save security' })).toBeFocused();
    expect(problems()).toEqual([]);

    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-security-${info.project.name}.png`, fullPage: true });

    await page.route('**/console/api/system/security', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }));
    await page.reload();
    await expect(page.getByRole('main').getByRole('alert')).toContainText('Something went wrong on our side.');
});
