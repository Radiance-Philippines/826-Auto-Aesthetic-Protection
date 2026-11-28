import { expect, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Users. The account made here is deleted again. */
const rows = (page: Page) => page.getByRole('table', { name: 'Users' }).locator('tbody tr');

async function openUsers(page: Page) {
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/users');
    await expect(rows(page).first()).toBeVisible();
}

test('invite, edit and delete a team member', async ({ page }, info) => {
    const name = `Console Member ${info.project.name} ${info.retry}`;
    const email = `console-member-${info.project.name}-${info.retry}@826auto.test`;
    const problems = watchProblems(page);
    await openUsers(page);

    await page.getByRole('button', { name: 'Invite team member' }).click();
    const invite = page.getByRole('dialog', { name: 'Invite team member' });
    await invite.getByRole('button', { name: 'Send invite' }).click();
    await expect(invite.getByText('Pick at least one branch.')).toBeVisible();
    await invite.getByRole('textbox', { name: 'Name' }).fill(name);
    await invite.getByRole('textbox', { name: 'Email' }).fill(email);
    await invite.locator('label', { hasText: 'Cainta' }).click();
    await invite.getByRole('button', { name: 'Send invite' }).click();
    await expect(invite).toBeHidden();
    const row = rows(page).filter({ hasText: email });
    await expect(row).toContainText('Invite sent');
    await expect(row).toContainText('Cainta');
    // The 422 above is the only failed request allowed
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await row.getByRole('button', { name: `Edit ${name}` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${name}` });
    await expect(edit.getByRole('checkbox', { name: 'See customers' })).toBeChecked();
    await edit.locator('label', { hasText: 'See customers' }).click();
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();
    await expect(row).toContainText('own access list');

    await row.getByRole('button', { name: `More actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Resend invite' }).click();
    await page.getByRole('alertdialog', { name: 'Resend invite' }).getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText(`New invite sent to ${email}`).first()).toBeVisible();

    await row.getByRole('button', { name: `More actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('alertdialog', { name: `Delete ${name}` }).getByRole('button', { name: 'Delete' }).click();
    await expect(row).toHaveCount(0);
});

test('view as a team member, then back to Users', async ({ page }) => {
    await openUsers(page);
    await page.getByRole('searchbox', { name: 'Search users' }).fill('Detailer');
    await page.getByRole('button', { name: 'More actions for Detailer' }).click();
    await page.getByRole('menuitem', { name: 'View as this user' }).click();

    await expect(page).toHaveURL(/\/console\/operations/);
    const banner = page.getByRole('region', { name: 'Viewing as another person' });
    await expect(banner).toContainText('You are viewing as Detailer');
    await banner.getByRole('button', { name: 'Stop impersonating' }).click();
    await expect(page).toHaveURL(/\/console\/owner\/users$/);
    await expect(rows(page).first()).toBeVisible();
});

test('users: failed load, accessible light and dark, fits 360px', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    let fail = true;
    await page.route('**/console/api/owner/users', (route) => (fail ? route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }) : route.continue()));
    await page.goto('/console/owner/users');
    await expect(page.getByRole('main').getByRole('alert')).toContainText('Something went wrong on our side.');
    fail = false;
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(rows(page).first()).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-users-${info.project.name}.png` });

    await page.getByRole('button', { name: 'Invite team member' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expectAccessible(page);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();

    await page.emulateMedia({ colorScheme: 'dark' });
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-users-${info.project.name}-360-dark.png`, fullPage: true });
});
