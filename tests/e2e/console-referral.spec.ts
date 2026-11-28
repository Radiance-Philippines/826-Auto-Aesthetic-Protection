import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Referral. Nothing is saved: the site's code box follows this setting. */
test('the discount follows its type, a refusal, and the screen is accessible and fits 360px', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/referral');

    await page.getByRole('radio', { name: 'Percent off' }).click();
    const value = page.getByRole('textbox', { name: 'Percent off the price' });
    await value.fill('500');
    await page.getByRole('button', { name: 'Save referral' }).click();
    await expect(value).toHaveAttribute('aria-invalid', 'true');
    await page.getByRole('radio', { name: 'Pesos off' }).click();
    await expect(page.getByRole('textbox', { name: 'Pesos off the price' })).toHaveValue('500');
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-referral-${info.project.name}.png`, fullPage: true });
});

test('a save says so', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    await page.route('**/console/api/owner/referral', async (route) => {
        if (route.request().method() === 'POST') {
            return route.fulfill({ json: { ...route.request().postDataJSON(), types: [{ value: 'fixed', label: 'Pesos off' }] } });
        }

        return route.continue();
    });
    await page.goto('/console/owner/referral');
    await page.getByRole('button', { name: 'Save referral' }).click();
    await expect(page.getByText('Referral saved').first()).toBeVisible();
});
