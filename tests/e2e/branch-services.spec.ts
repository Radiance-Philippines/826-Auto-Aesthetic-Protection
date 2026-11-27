import { expect, test } from '@playwright/test';

import { adminLogin, adminOpen, main, TEAM } from './admin-support';
import { watchProblems } from './support';

test('a branch manager turns a service off for their branch and on again', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.manager);
    await adminOpen(page, '/operations/branch-services');

    const first = main(page).getByRole('checkbox').first();
    const save = main(page).getByRole('button', { name: 'Save services' }).first();
    await expect(first).toBeChecked();
    await page.screenshot({ path: `test-results/branch-services-${info.project.name}.png`, fullPage: true });

    for (const wanted of [false, true]) {
        await first.press('Space');
        await save.click();
        await page.waitForLoadState('networkidle');
        await expect(main(page).getByRole('checkbox').first()).toBeChecked({ checked: wanted });
    }

    expect(problems()).toEqual([]);
});
