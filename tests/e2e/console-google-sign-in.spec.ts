import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Google sign-in. Only an Apple id is saved, and cleared again: the site's sign-in stays as it was. */
test('save, put back, copy, accessible and fits 360px', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/system/google-sign-in');

    await expect(page.getByText(/Authorised redirect URI:.*\/auth\/google\/callback/)).toBeVisible();
    const apple = page.getByRole('textbox', { name: 'Apple client ID' });
    const original = await apple.inputValue();
    await apple.fill('ph.example.console');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Customer app sign-in: on')).toBeVisible();
    await expect(apple).toHaveValue('ph.example.console');
    await apple.fill(original);
    const restored = page.waitForResponse((response) => response.url().endsWith('/google-sign-in') && response.request().method() === 'POST');
    // From the keyboard: on a phone the first save's message sits over the button for a while
    await apple.press('Control+s');
    expect((await restored).status()).toBe(200);
    await expect(page.getByRole('button', { name: 'Copy redirect uri' })).toBeVisible();
    expect(problems()).toEqual([]);

    await page.reload();
    await expect(apple).toBeVisible();
    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-google-sign-in-${info.project.name}.png`, fullPage: true });
});

test('turning it off asks first', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    const sent: string[] = [];
    await page.route('**/console/api/system/google-sign-in**', async (route) => {
        if (route.request().method() === 'POST') {
            sent.push(new URL(route.request().url()).pathname);
        }

        await route.fulfill({
            json: { clientId: 'web.apps.googleusercontent.com', androidClientId: null, iosClientId: null, appleClientId: null, hasSecret: true, enabled: sent.length === 0, appEnabled: true, redirectUrl: 'https://example.test/auth/google/callback', said: sent.length ? { title: 'Google sign-in is off', body: null } : null },
        });
    });
    await page.goto('/console/system/google-sign-in');

    await page.getByRole('button', { name: 'Turn off Google sign-in' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Cancel' }).click();
    expect(sent).toEqual([]);
    await page.getByRole('button', { name: 'Turn off Google sign-in' }).click();
    await page.getByRole('alertdialog', { name: 'Turn off Google sign-in' }).getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText('Google sign-in is off').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Turn off Google sign-in' })).toHaveCount(0);
    expect(sent).toEqual([expect.stringContaining('/google-sign-in/turn-off')]);
});
