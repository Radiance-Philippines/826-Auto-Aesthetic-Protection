import { expect, test } from '@playwright/test';

import { adminLogin, adminOpen, TEAM } from './admin-support';
import { watchProblems } from './support';

const trigger = (page: import('@playwright/test').Page) => page.getByRole('button', { name: /^Branch: / });

test('the super admin picks one branch with the keyboard and goes back to all', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await adminOpen(page, '/sales/inbox');

    await expect(trigger(page)).toHaveAccessibleName('Branch: All branches');
    // The top bar still fits: the account button is not pushed off the screen
    await expect(page.locator('.fi-user-menu-trigger')).toBeInViewport({ ratio: 1 });
    await expect(page.getByRole('navigation', { name: 'Branches' })).toBeVisible();

    await trigger(page).press('Enter');
    const options = page.getByRole('menuitemradio');
    await expect(options.first()).toBeVisible();
    await page.screenshot({ path: `test-results/branch-menu-${info.project.name}.png` });
    const name = (await options.nth(1).innerText()).trim();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');

    await expect(trigger(page)).toHaveAccessibleName(`Branch: ${name}`);
    await expect(page.getByRole('navigation', { name: 'Branches' })).toHaveCount(0);
    await page.screenshot({ path: `test-results/branch-chosen-${info.project.name}.png` });

    // It stays on the next page too
    await adminOpen(page, '/owner/branch-blocks');
    await expect(trigger(page)).toHaveAccessibleName(`Branch: ${name}`);

    await trigger(page).click();
    await page.getByRole('menuitemradio', { name: 'All branches' }).click();
    await expect(trigger(page)).toHaveAccessibleName('Branch: All branches');
    expect(problems()).toEqual([]);
});

test('someone with one branch sees its name and cannot switch', async ({ page }) => {
    await adminLogin(page, TEAM.sales);
    await adminOpen(page, '/sales/inbox');

    await expect(page.locator('[data-branch-selector]')).toBeVisible();
    await expect(trigger(page)).toHaveCount(0);
});
