import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Email settings. The seeded mailbox is saved as it is; mail goes to a file. */
test('save, send a test, and the password stays on the server', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    const read = page.waitForResponse('**/console/api/system/email-settings');
    await page.goto('/console/system/email-settings');
    expect(await (await read).text()).not.toContain('not-a-real-password');

    const password = page.getByLabel('Mailbox password', { exact: true });
    await expect(password).toHaveValue('');
    await expect(page.getByText('Saved (hidden). Leave blank to keep it')).toBeVisible();

    const notify = page.getByRole('textbox', { name: 'Send booking alerts to' });
    await notify.fill('not-an-email');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(notify).toHaveAttribute('aria-invalid', 'true');
    await notify.fill('alerts@826auto.test');

    await password.fill('typed');
    await page.getByRole('button', { name: 'Show mailbox password' }).click();
    await expect(password).toHaveAttribute('type', 'text');
    await password.fill('');

    await page.getByRole('button', { name: 'Server' }).click();
    await expect(page.getByRole('textbox', { name: 'Outgoing (SMTP) server' })).toBeVisible();

    await page.getByRole('button', { name: 'Save and send a test' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Email works' })).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-email-settings-${info.project.name}.png`, fullPage: true });
});
