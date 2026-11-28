import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Site settings. The tagline is put back; no picture is saved. */
test('a refusal, then a save that is put back', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/site-settings');

    const tagline = page.getByRole('textbox', { name: 'Tagline' });
    const link = page.getByRole('textbox', { name: 'Instagram' });
    const original = await tagline.inputValue();
    const originalLink = await link.inputValue();
    await link.fill('http://instagram.com/x');
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(link).toHaveAttribute('aria-invalid', 'true');
    await link.fill(originalLink);

    await tagline.fill(`Console tagline ${info.project.name}`);
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Site settings saved').first()).toBeVisible();
    await page.reload();
    await expect(tagline).toHaveValue(`Console tagline ${info.project.name}`);
    await tagline.fill(original);
    const restored = page.waitForResponse((response) => response.url().endsWith('/site-settings') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save settings' }).click();
    expect((await restored).status()).toBe(200);

    // A chosen picture shows its name before anything is sent
    const chooser = page.waitForEvent('filechooser');
    await page.getByRole('button', { name: /picture$/ }).first().click();
    await (await chooser).setFiles({ name: 'hero.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') });
    await expect(page.getByText('hero.png')).toBeVisible();
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);
});

test('site settings are accessible, light and dark, and fit 360px', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/site-settings');
    await expect(page.getByRole('button', { name: 'Save settings' })).toBeVisible();
    await expectAccessible(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({ path: `test-results/console-site-settings-${info.project.name}.png`, fullPage: true });
});
