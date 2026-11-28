import { rmSync } from 'node:fs';

import { expect, type Page, test } from '@playwright/test';

import { emailedCodeFor, PASSWORD, sentMail, TEAM, totp } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, isPhone, watchProblems } from './support';

/** The React admin's own sign-in, forgot-password and reset pages */
const heading = (page: Page) => page.getByRole('heading', { level: 1 });
const password = (page: Page) => page.getByRole('textbox', { name: 'Password', exact: true }).or(page.locator('input[name="password"]'));
const signInButton = (page: Page) => page.getByRole('button', { name: 'Sign in', exact: true });

/** The budgets are a few tries a minute, and the tests try far more often */
const freshBudgets = () => rmSync('storage/framework/cache/data', { recursive: true, force: true });

async function fill(page: Page, email: string, secret = PASSWORD) {
    await page.getByLabel('Email').fill(email);
    await password(page).fill(secret);
    await signInButton(page).click();
}

async function open(page: Page, path = '/console/login') {
    freshBudgets();
    await page.goto(path);
    await expect(heading(page)).toBeVisible();
}

test('a signed-out visitor gets the split sign-in page, and then the page asked for', async ({ page }, info) => {
    const problems = watchProblems(page);
    await open(page, '/console/operations/queue');
    await expect(page).toHaveURL(/\/console\/login$/);
    await expect(heading(page)).toHaveText('Sign in');

    const brand = (await page.getByRole('banner').boundingBox())!;
    const form = (await page.getByRole('main').boundingBox())!;

    if (isPhone(info)) {
        expect(brand.y + brand.height).toBeLessThanOrEqual(form.y + 1);
        expect(brand.height).toBeLessThan(200);
        // The form is on the first screen
        await expect(signInButton(page)).toBeInViewport({ ratio: 1 });
    } else {
        expect(brand.x + brand.width).toBeLessThanOrEqual(form.x + 1);
        expect(brand.height).toBeGreaterThanOrEqual(900);
        await expect(page.getByRole('link', { name: 'View the website' })).toBeVisible();
    }

    await expect(page.getByRole('banner').getByRole('img', { name: '826' })).toBeVisible();
    await expect(page.getByText('Auto Aesthetic & Protection')).toBeVisible();
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    await fill(page, TEAM.operations);
    await expect(page).toHaveURL(/\/console\/operations\/queue$/);
    expect(problems()).toEqual([]);
});

test('a wrong password says so, and the password can be shown', async ({ page }) => {
    await open(page);
    await password(page).fill('not the password');
    await expect(password(page)).toHaveAttribute('type', 'password');
    await page.getByRole('button', { name: 'Show password' }).click();
    await expect(password(page)).toHaveAttribute('type', 'text');

    await fill(page, TEAM.owner, 'not the password');
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    await expect(page).toHaveURL(/\/console\/login$/);
    await expectAccessible(page);
});

test('an authenticator code is asked for, survives a reload, and signs in', async ({ page }) => {
    await open(page);
    await fill(page, TEAM.twoFactor);
    await expect(heading(page)).toHaveText('Two-factor sign-in');
    await expect(heading(page)).toBeVisible();

    // The password alone opens nothing
    expect((await page.request.get('/console/api/owner/me', { headers: { Accept: 'application/json' } })).status()).toBe(401);
    await page.reload();
    await expect(heading(page)).toHaveText('Two-factor sign-in');

    // Typed key by key: the keyboard starts in the code box and stays there
    await expect(page.getByRole('textbox', { name: 'Code' })).toBeFocused();
    const wrong = totp() === '000000' ? '111111' : '000000';
    await page.keyboard.type(wrong);
    await expect(page.getByRole('textbox', { name: 'Code' })).toHaveValue(wrong);
    await page.keyboard.press('Enter');
    await expect(page.getByText('That code isn’t right. Check it and try again.')).toBeVisible();
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'Use a recovery code' }).click();
    await expect(page.getByRole('textbox', { name: 'Recovery code' })).toBeFocused();
    await page.getByRole('button', { name: 'Use the authenticator app' }).click();
    await page.getByRole('button', { name: 'Back to sign in' }).click();
    await expect(page.getByLabel('Email')).toBeFocused();
    await page.keyboard.type(TEAM.twoFactor);
    await expect(page.getByLabel('Email')).toHaveValue(TEAM.twoFactor);
    freshBudgets();
    await password(page).fill(PASSWORD);
    await signInButton(page).click();

    freshBudgets();
    await page.getByRole('textbox', { name: 'Code' }).fill(totp());
    await page.getByRole('button', { name: 'Verify and sign in' }).click();
    await expect(page).toHaveURL(/\/console\/owner\/dashboard$/);
});

test('an emailed code signs in', async ({ page }) => {
    await open(page);
    const before = sentMail().length;
    await fill(page, TEAM.emailCode);
    await expect(heading(page)).toHaveText('Two-factor sign-in');
    await expect.poll(() => emailedCodeFor(TEAM.emailCode, before)).not.toBeNull();

    await page.getByRole('textbox', { name: 'Code' }).fill(emailedCodeFor(TEAM.emailCode, before)!);
    await page.getByRole('button', { name: 'Verify and sign in' }).click();
    await expect(page).toHaveURL(/\/console\/owner\/dashboard$/);
});

test('signing out from the console lands on its sign-in page', async ({ page }) => {
    await open(page);
    await fill(page, TEAM.owner);
    await expect(page).toHaveURL(/\/console\/owner\/dashboard$/);

    await page.getByRole('button', { name: /^Account:/ }).click();
    const out = page.getByRole('menuitem', { name: 'Sign out' });
    await expect(out).toBeInViewport({ ratio: 1 });
    await out.click();

    await expect(page).toHaveURL(/\/console\/login$/);
    await page.goto('/console/owner');
    await expect(page).toHaveURL(/\/console\/login$/);
});

test('a forgotten password is chosen again from the emailed link', async ({ page }, info) => {
    // One account per project: the broker sends one link a minute to an address
    const email = isPhone(info) ? TEAM.sales : TEAM.manager;
    await open(page);
    await page.getByRole('link', { name: 'Forgot password?' }).click();
    await expect(heading(page)).toHaveText('Forgot your password?');
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    const before = sentMail().length;
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Email me the link' }).click();
    await expect(page.getByRole('status')).toContainText('If that address belongs to a team account');

    const link = () => sentMail().slice(before).match(/https?:\/\/[^\s"'<>)\]]*console\/reset-password\?[^\s"'<>)\]]*/)?.[0].replace(/&amp;/g, '&') ?? null;
    await expect.poll(link).not.toBeNull();
    await page.goto(link()!);
    await expect(heading(page)).toHaveText('Choose a new password');
    await expect(page.getByText(email)).toBeVisible();
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    // The same password again, so the other tests still know it
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('input[name="password_confirmation"]').fill('something else');
    await page.getByRole('button', { name: 'Save new password' }).click();
    await expect(page.getByText('The two passwords don’t match.')).toBeVisible();
    await page.locator('input[name="password_confirmation"]').fill(PASSWORD);
    await page.getByRole('button', { name: 'Save new password' }).click();

    await expect(page).toHaveURL(/\/console\/login$/);
    await expect(page.getByRole('status')).toContainText('Your password has been reset.');
    await fill(page, email);
    await expect(page).toHaveURL(/\/console\/(sales|operations)\//);
});

test('a dead reset link says so and offers a new one', async ({ page }) => {
    await open(page, '/console/reset-password');
    await expect(heading(page)).toHaveText('This link can’t be used');
    await expect(page.getByRole('link', { name: 'Ask for a new link' })).toBeVisible();

    await open(page, `/console/reset-password?token=nope&email=${TEAM.owner}`);
    await page.locator('input[name="password"]').fill('a-brand-new-password');
    await page.locator('input[name="password_confirmation"]').fill('a-brand-new-password');
    await page.getByRole('button', { name: 'Save new password' }).click();
    await expect(page.getByRole('alert')).toContainText('This link is invalid or has expired.');
    await expectAccessible(page);
});

test('nothing runs off a 360px screen, in light and dark', async ({ page }, info) => {
    test.skip(!isPhone(info), 'phone widths');
    await page.setViewportSize({ width: 360, height: 640 });

    for (const colorScheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme });

        for (const path of ['/console/login', '/console/forgot-password', `/console/reset-password?token=x&email=${TEAM.owner}`, '/console/reset-password']) {
            await open(page, path);
            await expectNoHorizontalOverflow(page);
            await expectAccessible(page);
        }
    }
});

test('screenshots of the sign-in page', async ({ page }, info) => {
    const size = isPhone(info) ? '390x844' : '1440x900';

    for (const colorScheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme });
        await open(page);
        await page.getByRole('banner').getByRole('img', { name: '826' }).evaluate((image: HTMLImageElement) => image.decode());
        await page.evaluate(() => document.fonts.ready);
        await expectAccessible(page);
        await page.screenshot({ path: `test-results/console-auth/login-${size}-${colorScheme}.png`, scale: 'css' });
    }
});
