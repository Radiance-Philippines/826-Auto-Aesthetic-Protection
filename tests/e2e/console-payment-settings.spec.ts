import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Settings → Payments. The booking tests count on the fee
 * being off and no accounts, so nothing here is saved to the database: the
 * save is answered by the test, and the real one is only sent an invalid form.
 */
const BASE = '/console/owner/payment-settings';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAACgAAAAeCAIAAADRv8uKAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAALklEQVRIie3NIREAQAgAsOclTTn6eyrgMFuBRXa9C/9kFYvFYrFYLBaLxWLxygACLQEagDMW+QAAAABJRU5ErkJggg==', 'base64');

test('the fee shows its fields, a bad one is refused, and Save payments sends the form with the QR', async ({ page }) => {
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);
    const fee = page.getByRole('switch', { name: 'Ask for a reservation fee' });
    await expect(fee).not.toBeChecked();
    await expect(page.getByRole('textbox', { name: 'Amount (₱)' })).toHaveCount(0);
    await expectAccessible(page);

    await fee.click();
    await page.getByRole('textbox', { name: 'Amount (₱)' }).fill('0');
    await page.getByRole('button', { name: 'Save payments' }).click();
    await expect(page.getByText('The amount field must be at least 1.')).toBeVisible();
    // The server's 422 above is the only failed request allowed
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);
    await expectAccessible(page);

    const gcash = page.getByRole('region', { name: 'GCash' });
    await gcash.getByRole('switch', { name: 'Accept GCash' }).click();
    await gcash.getByRole('textbox', { name: 'GCash number' }).fill('0917 000 0000');
    await gcash.locator('input[type=file]').setInputFiles({ name: 'qr.png', mimeType: 'image/png', buffer: PNG });
    await expect(gcash.getByRole('list', { name: 'Chosen: gcash qr code' })).toContainText('qr.png');
    await page.getByRole('textbox', { name: 'Amount (₱)' }).fill('500');

    let sent = '';
    await page.route('**/console/api/owner/payment-settings', async (route) => {
        if (route.request().method() !== 'POST') {
            return route.fallback();
        }

        sent = route.request().headers()['content-type'] ?? '';
        const values = { reservationFeeEnabled: false, amountValue: null, holdMinutes: 30, instructions: null, gcashEnabled: false, gcashName: null, gcashNumber: null, mayaEnabled: false, mayaName: null, mayaNumber: null };
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ values, gcashQrUrl: null, mayaQrUrl: null }) });
    });
    await page.keyboard.press('Control+s');
    await expect(page.getByText('Payments saved')).toBeVisible();
    expect(sent).toContain('multipart/form-data');

    await expectNoHorizontalOverflow(page);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    // Colour transitions (and the toast's) have to finish before axe reads contrast
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});

test('the QR chosen reaches the server', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    await page.goto(BASE);
    // Refused for its amount, so nothing is stored; the QR itself passes
    await page.getByRole('switch', { name: 'Ask for a reservation fee' }).click();
    await page.getByRole('textbox', { name: 'Amount (₱)' }).fill('');
    await page.getByRole('region', { name: 'Maya' }).locator('input[type=file]').setInputFiles({ name: 'qr.png', mimeType: 'image/png', buffer: PNG });
    const answered = page.waitForResponse((response) => response.url().endsWith('/payment-settings') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save payments' }).click();
    const response = await answered;

    expect(response.status()).toBe(422);
    expect(Object.keys((await response.json()).errors)).toEqual(['amountValue']);
});
