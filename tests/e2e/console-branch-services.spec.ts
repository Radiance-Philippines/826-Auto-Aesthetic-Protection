import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Services of a branch. Cainta's ticks are used by the
 * booking tests, so the real save here changes nothing; a changed tick is
 * only looked at on its way out.
 */
test('a branch manager sees their branch and saves its services', async ({ page }) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.manager);
    await page.goto('/console/operations/branch-services');

    const branch = page.getByRole('region', { name: 'Cainta – Ortigas Extension' });
    const graphene = branch.getByRole('checkbox', { name: 'Graphene coating', exact: true });
    await expect(graphene).toBeChecked();
    await expect(page.getByRole('region', { name: 'San Mateo' })).toHaveCount(0);

    const saved = page.waitForResponse('**/branch-services/*');
    await branch.getByRole('button', { name: 'Save services' }).click();
    expect((await saved).status()).toBe(204);
    await expect(page.getByText('Cainta – Ortigas Extension: services saved')).toBeVisible();
    await page.reload();
    await expect(graphene).toBeChecked();

    await page.route('**/branch-services/*', (route) => route.fulfill({ status: 204 }));
    const total = await branch.getByRole('checkbox').count();
    await graphene.press('Space');
    await expect(graphene).not.toBeChecked();
    const sent = page.waitForRequest('**/branch-services/*');
    await branch.getByRole('button', { name: 'Save services' }).click();
    expect((await sent).postDataJSON().services).toHaveLength(total - 1);

    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    // Colour transitions (and the toast's) have to finish before axe reads contrast
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
    expect(problems()).toEqual([]);
});
