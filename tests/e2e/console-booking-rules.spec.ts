import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Booking rules. Only the terms are saved, and put back. */
test('refusals, a save with Ctrl+S and the hidden limits', async ({ page }, info) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/booking-rules');

    const hours = page.getByRole('textbox', { name: 'Cancel online up to' });
    const before = await hours.inputValue();
    await hours.fill('999');
    await page.getByRole('button', { name: 'Save rules' }).click();
    await expect(hours).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByRole('alert').filter({ hasText: 'Check the fields marked below.' })).toBeVisible();
    await hours.fill(before);

    const moving = page.getByRole('switch', { name: 'Customers can move their booking' });
    await moving.click();
    await expect(page.getByRole('textbox', { name: 'Move online up to' })).toHaveCount(0);
    await moving.click();
    await expect(page.getByRole('textbox', { name: 'Move online up to' })).toBeVisible();

    const terms = page.getByRole('textbox', { name: 'Shown before customers reserve' });
    const original = await terms.inputValue();
    await terms.fill(`Console terms ${info.project.name}`);
    await terms.press('Control+s');
    await expect(page.getByText('Booking rules saved').first()).toBeVisible();
    await page.reload();
    await expect(terms).toHaveValue(`Console terms ${info.project.name}`);
    await terms.fill(original);
    const restored = page.waitForResponse((response) => response.url().endsWith('/booking-rules') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save rules' }).click();
    expect((await restored).status()).toBe(200);
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);
});

test('booking rules are accessible, light and dark, and fit 360px', async ({ page }, info) => {
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/booking-rules');
    await expect(page.getByRole('button', { name: 'Save rules' })).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-booking-rules-${info.project.name}.png`, fullPage: true });
    await page.emulateMedia({ colorScheme: 'dark' });
    await expectAccessible(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
});
