import { expect, type Page, test } from '@playwright/test';

import { adminLogin, adminOpen, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, isPhone, watchProblems } from './support';

/** The React admin's frame around every screen: launcher, user menu, banner, sidebar */
const heading = (page: Page) => page.getByRole('heading', { level: 1 });

test('the launcher switches panel, and each panel opens on its dashboard', async ({ page }) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console');
    await expect(page).toHaveURL(/\/console\/owner\/dashboard$/);
    await expect(heading(page)).toHaveText('Dashboard');

    await page.getByRole('button', { name: 'Switch panel' }).click();
    await expect(page.getByRole('menuitem', { name: /HR/ })).toHaveAttribute('aria-disabled', 'true');
    await page.getByRole('menuitem', { name: /^Sales/ }).click();
    await expect(page).toHaveURL(/\/console\/sales\/dashboard$/);
    await expect(heading(page)).toHaveText('Dashboard');
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    expect(problems()).toEqual([]);
});

test('a screen that is not ported says so and leads to the current admin', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    // A sidebar entry with no screen here, whatever has been ported so far
    await page.route('**/console/api/owner/me', async (route) => {
        const response = await route.fetch();
        const me = await response.json();
        me.navigation.push({ label: null, items: [{ key: 'later', here: true, label: 'Later screen', href: '/owner/later', badge: null, badgeTooltip: null }] });
        await route.fulfill({ response, json: me });
    });
    await page.goto('/console/owner/later');

    await expect(heading(page)).toHaveText('Later screen');
    await expect(page.getByRole('link', { name: 'Open Later screen in the current admin' })).toHaveAttribute('href', /\/owner\/later$/);
});

test('moving between screens keeps the page and puts the keyboard at the new one', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner');
    await page.evaluate(() => ((window as unknown as { __mark: string }).__mark = 'same page'));

    if (isPhone(info)) {
        await page.getByRole('button', { name: 'Open the menu' }).click();
    }

    await page.getByRole('navigation', { name: 'Panel' }).getByRole('link', { name: 'Vehicle sizes' }).click();
    await expect(heading(page)).toHaveText('Vehicle sizes');
    await expect(page.getByRole('main')).toBeFocused();
    await expect(page).toHaveTitle('Vehicle sizes · 826');
    expect(await page.evaluate(() => (window as unknown as { __mark?: string }).__mark)).toBe('same page');
});

test('the appearance choice is kept, and the current admin follows it', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner');
    const html = page.locator('html');
    await expect(html).not.toHaveClass(/dark/);

    await page.getByRole('button', { name: /^Account:/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Profile' })).toHaveAttribute('href', /\/console\/owner\/profile$/);
    // On a phone the menu is a sheet that slides up: wait until the choice is all there
    const dark = page.getByRole('menuitemradio', { name: 'Dark' });
    await expect(dark).toBeInViewport({ ratio: 1 });
    await dark.click();
    await expect(html).toHaveClass(/dark/);

    await page.reload();
    await expect(html).toHaveClass(/dark/);
    await adminOpen(page, '/owner');
    await expect(html).toHaveClass(/dark/);
});

test('sign out ends the session for the console too', async ({ page }) => {
    await adminLogin(page, TEAM.operations);
    await page.goto('/console/operations/queue');

    // One branch: its name, nothing to pick
    await expect(page.getByRole('banner').getByText(/^Branch: /)).toHaveCount(1);
    await expect(page.getByRole('button', { name: /^Branch: / })).toHaveCount(0);

    await page.getByRole('button', { name: /^Account:/ }).click();
    const out = page.getByRole('menuitem', { name: 'Sign out' });
    await expect(out).toBeInViewport({ ratio: 1 });
    await out.click();
    await expect(page).toHaveURL(/\/login$/);

    await page.goto('/console/operations/queue');
    await expect(page).toHaveURL(/\/console\/login$/);
});

test('viewing as someone else shows the banner with its way back', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    // The server's own answer is covered in PHPUnit (ConsoleShellTest); here, that it is drawn
    await page.route('**/console/api/owner/me', async (route) => {
        const response = await route.fetch();
        await route.fulfill({
            response,
            json: { ...(await response.json()), impersonating: { name: 'Sample Detailer', roleLabel: 'Operations (detailers)', branches: 'Cainta', stopUrl: '/impersonation/stop' } },
        });
    });
    await page.goto('/console/owner');

    const banner = page.getByRole('region', { name: 'Viewing as another person' });
    await expect(banner).toContainText('You are viewing as Sample Detailer (Operations (detailers), Cainta).');
    await expect(banner.getByRole('button', { name: 'Stop impersonating' })).toBeVisible();
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
});
