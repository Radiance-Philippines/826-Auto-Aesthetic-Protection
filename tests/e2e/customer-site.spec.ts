import { expect, type Page, test } from '@playwright/test';

import { evidence } from './evidence';
import { expectNoHorizontalOverflow, signIn, watchProblems } from './support';

/**
 * The customer's own pages on the site: sign-in, sign-up, password reset and /account, signed out
 * (sent to /login?return=) and signed in as the sample customer (/auth/local), at the phone (390 px)
 * and desktop (1440 px) projects, in light and dark.
 */
const AUTH = [
    { name: 'login', path: '/login' },
    { name: 'register', path: '/register' },
    { name: 'forgot-password', path: '/forgot-password' },
] as const;

const ACCOUNT = [
    { name: 'overview', path: '/account' },
    { name: 'garage', path: '/account/garage' },
    { name: 'rewards', path: '/account/rewards' },
    { name: 'warranty', path: '/account/warranty' },
    { name: 'me', path: '/account/me' },
] as const;

const waitShell = async (page: Page) => {
    await page.locator('main#main h1, main#main h2').first().waitFor();
    await page.waitForLoadState('networkidle');
};

for (const scheme of ['light', 'dark'] as const) {
    test.describe(`customer site, ${scheme}`, () => {
        test.use({ colorScheme: scheme });

        for (const { name, path } of AUTH) {
            test(`${path} opens signed out without problems`, async ({ page }, info) => {
                const problems = watchProblems(page);
                await page.goto(path);
                await waitShell(page);
                await expect(page).toHaveURL(new RegExp(`${path}$`));
                await expect(page.locator('main#main form')).toBeVisible();
                await expectNoHorizontalOverflow(page);
                await evidence(page, info, 'card677', `${name}-${scheme}`);
                expect(problems()).toEqual([]);
            });
        }

        for (const { name, path } of ACCOUNT) {
            test(`${path} sends a signed-out visitor to the sign-in page`, async ({ page }, info) => {
                await page.goto(path);
                await waitShell(page);
                await expect(page).toHaveURL(new RegExp(`/login\\?return=${encodeURIComponent(path).replace(/\./g, '\\.')}$`));
                await expect(page.locator('main#main form')).toBeVisible();

                if (name === 'overview') {
                    await evidence(page, info, 'card683', `signed-out-redirect-${scheme}`);
                }
            });

            test(`${path} opens for the signed-in sample customer`, async ({ page }, info) => {
                const problems = watchProblems(page);
                await signIn(page, path);
                await waitShell(page);
                await expect(page).toHaveURL(new RegExp(`${path}$`));
                await expect(page.locator('main#main h1').first()).toBeVisible();
                await expectNoHorizontalOverflow(page);
                await evidence(page, info, 'card683', `${name}-${scheme}`);
                expect(problems()).toEqual([]);
            });
        }
    });
}

test.describe('customer site input hints', () => {
    test('login: email is the username, the password is current-password', async ({ page }) => {
        await page.goto('/login');
        await waitShell(page);
        const email = page.locator('#login-email');
        await expect(email).toHaveAttribute('type', 'email');
        await expect(email).toHaveAttribute('autocomplete', 'username');
        await expect(page.locator('#login-password')).toHaveAttribute('autocomplete', 'current-password');
    });

    test('register: name, email (username) and new-password', async ({ page }) => {
        await page.goto('/register');
        await waitShell(page);
        await expect(page.locator('#register-name')).toHaveAttribute('autocomplete', 'name');
        await expect(page.locator('#register-email')).toHaveAttribute('autocomplete', 'username');
        await expect(page.locator('#register-password')).toHaveAttribute('autocomplete', 'new-password');
    });

    test('forgot password: the email field is hinted', async ({ page }) => {
        await page.goto('/forgot-password');
        await waitShell(page);
        await expect(page.locator('main#main input[type=email]').first()).toHaveAttribute('autocomplete', /username|email/);
    });
});
