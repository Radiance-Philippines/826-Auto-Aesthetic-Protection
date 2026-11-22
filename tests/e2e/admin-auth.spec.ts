import { expect, test } from '@playwright/test';

import { adminLogin, emailedCodeFor, sentMail, submitLogin, TEAM, totp, typeCode } from './admin-support';
import { expectNoHorizontalOverflow, signIn, watchProblems } from './support';

test.describe('admin sign-in', () => {
    test('the sign-in page asks for email and password', async ({ page }) => {
        await page.goto('/admin/login');
        await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
        await expect(page.getByLabel('Email address')).toBeVisible();
        await expect(page.locator('#form\\.password')).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    test('the admin pages send a visitor to the sign-in page', async ({ page }) => {
        await page.goto('/admin/bookings');
        await expect(page).toHaveURL(/\/admin\/login/);
    });

    test('a wrong password is refused with a message and stays on the sign-in page', async ({ page }) => {
        await submitLogin(page, TEAM.owner, 'not the password');
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/login/);
    });

    test('a team member signs in, sees the dashboard, and signs out', async ({ page }) => {
        const problems = watchProblems(page);
        await adminLogin(page);
        await expect(page).toHaveURL(/\/admin\/?$/);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
        await expectNoHorizontalOverflow(page);

        await page.getByRole('button', { name: 'User menu' }).click();
        await page.locator('button, a').filter({ hasText: /^\s*Sign out\s*$/ }).first().click();
        await expect(page).toHaveURL(/\/admin\/login/);
        expect(problems()).toEqual([]);
    });

    test('a customer signed in on the site cannot open the admin', async ({ page }) => {
        await signIn(page);
        await page.goto('/admin/bookings');
        await expect(page).toHaveURL(/\/admin\/login/);
    });
});

test.describe('admin two-factor sign-in', () => {
    test('an account with an authenticator app is asked for a code, and a wrong one is refused', async ({ page }) => {
        await submitLogin(page, TEAM.twoFactor);
        await expect(page.locator('.fi-one-time-code-input-ctn')).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/login/);

        await typeCode(page, '000000');
        await expect(page.getByText(/invalid|incorrect|not valid/i).first()).toBeVisible();
        await expect(page).toHaveURL(/\/admin\/login/);
    });

    test('the right code from the authenticator signs in', async ({ page }) => {
        await submitLogin(page, TEAM.twoFactor);
        await expect(page.locator('.fi-one-time-code-input-ctn')).toBeVisible();
        await typeCode(page, totp());
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });

    test('an account with an emailed code gets it by email (captured, never sent) and signs in with it', async ({ page }) => {
        const before = sentMail().length;
        await submitLogin(page, TEAM.emailCode);
        await expect(page.locator('.fi-one-time-code-input-ctn')).toBeVisible();
        await expect.poll(() => emailedCodeFor(TEAM.emailCode, before)).not.toBeNull();
        await typeCode(page, emailedCodeFor(TEAM.emailCode, before)!);
        await page.waitForURL((url) => !url.pathname.endsWith('/login'));
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });
});
