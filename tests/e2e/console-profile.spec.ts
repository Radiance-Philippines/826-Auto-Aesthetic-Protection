import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, type Page, type TestInfo, test } from '@playwright/test';

import { emailedCodeFor, PASSWORD, sentMail, totp } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, isPhone, watchProblems } from './support';

/** The React admin's profile page: the person's own sign-in details */
const email = (info: TestInfo) => `profile-${info.project.name}@826auto.test`;

const artisan = (info: TestInfo, ...args: string[]) =>
    execFileSync('php', ['artisan', ...args, '--no-interaction'], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), CACHE_STORE: 'file', E2E_PROJECT: info.project.name, FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '' },
        stdio: 'pipe',
    });

const requireTwoFactor = (info: TestInfo, on: boolean) => artisan(info, 'tinker', `--execute=App\\Models\\SecuritySetting::instance()->update(["require_two_factor" => ${on}]);`);

const freshBudgets = () => rmSync('storage/framework/cache/data', { recursive: true, force: true });

const section = (page: Page, name: string) => page.getByRole('region', { name, exact: true });
const dialog = (page: Page) => page.getByRole('dialog');
const heading = (page: Page) => page.getByRole('heading', { level: 1 });

async function signIn(page: Page, address: string, origin = '') {
    freshBudgets();
    await page.goto(`${origin}/console/login`);
    await page.getByLabel('Email').fill(address);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await arrived(page);
}

/** Inside the console and settled: a screen still arriving moves the focus, which would close a menu just opened */
async function arrived(page: Page) {
    await expect(page).toHaveURL(/\/console\/operations\/(queue|profile)$/);
    await page.waitForLoadState('networkidle');
}

async function openProfile(page: Page) {
    await page.getByRole('button', { name: /^Account:/ }).click();
    // On a phone the menu is a sheet that slides up: it is still when its last item is all there
    await expect(page.getByRole('menuitem', { name: 'Sign out' })).toBeInViewport({ ratio: 1 });
    await page.getByRole('menuitem', { name: 'Profile' }).click();
    await expect(page).toHaveURL(/\/console\/operations\/profile$/);
    await expect(heading(page)).toHaveText('Profile');
}

async function signOut(page: Page) {
    await page.getByRole('button', { name: /^Account:/ }).click();
    const out = page.getByRole('menuitem', { name: 'Sign out' });
    await expect(out).toBeInViewport({ ratio: 1 });
    await out.click();
    await expect(page).toHaveURL(/\/console\/login$/);
}

test.beforeAll(({}, info) => {
    artisan(info, 'db:seed', '--class=E2eProfileSeeder', '--force');
});

test('the user menu opens the console profile, where the name and password change', async ({ page }, info) => {
    const problems = watchProblems(page);
    await signIn(page, email(info));
    await openProfile(page);
    const account = section(page, 'Account');
    await expect(account.getByLabel('Name')).toHaveValue('Profile Tester');
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    for (const colorScheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme });
        await page.screenshot({ path: `test-results/console-auth/profile-${isPhone(info) ? '390x844' : '1440x900'}-${colorScheme}.png`, fullPage: true, scale: 'css' });
        await expectAccessible(page);
    }

    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);

    freshBudgets();
    // The same password again, so the later tests still know it
    await account.getByRole('textbox', { name: 'New password', exact: true }).or(account.locator('input[autocomplete="new-password"]').first()).fill(PASSWORD);
    await account.locator('input[autocomplete="new-password"]').nth(1).fill(PASSWORD);
    await account.getByRole('button', { name: 'Save profile' }).click();
    await expect(account.getByText(/current password field is required/i)).toBeVisible();
    await account.locator('input[autocomplete="current-password"]').fill(PASSWORD);
    await account.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByRole('region', { name: 'Notifications' }).getByText('Profile saved')).toBeVisible();

    // Still signed in after the password change
    await page.reload();
    await expect(heading(page)).toHaveText('Profile');
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);
});

test('an authenticator is set up with its QR code, signs in, and is turned off with a recovery code', async ({ page }, info) => {
    await signIn(page, email(info));
    await openProfile(page);
    const twoFactor = section(page, 'Two-factor sign-in');

    await twoFactor.getByRole('button', { name: 'Set up an authenticator app' }).click();
    await expect(dialog(page).getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible();
    const secret = (await dialog(page).getByTestId('totp-secret').textContent())!;
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);
    freshBudgets();
    await dialog(page).getByRole('textbox', { name: 'Code' }).fill(totp(secret));
    await dialog(page).locator('input[autocomplete="current-password"]').fill(PASSWORD);
    await dialog(page).getByRole('button', { name: 'Turn on' }).click();

    const codes = page.getByRole('region', { name: 'Your recovery codes' });
    await expect(codes.getByRole('listitem')).toHaveCount(8);
    const recovery = (await codes.getByRole('listitem').first().textContent())!;
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);
    await codes.getByRole('button', { name: 'I saved them' }).click();
    await expect(codes).toBeHidden();

    await signOut(page);
    freshBudgets();
    await page.getByLabel('Email').fill(email(info));
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    // The code that set it up is spent; the next one works
    await page.getByRole('textbox', { name: 'Code' }).fill(totp(secret, Date.now() + 30_000));
    await page.getByRole('button', { name: 'Verify and sign in' }).click();
    await arrived(page);
    await expect(heading(page)).toHaveText('Queue');

    await openProfile(page);
    freshBudgets();
    await twoFactor.getByRole('button', { name: 'Turn off the authenticator app' }).click();
    await dialog(page).locator('input[autocomplete="current-password"]').fill(PASSWORD);
    await dialog(page).getByLabel('Code or recovery code').fill(recovery);
    await dialog(page).getByRole('button', { name: 'Turn off' }).click();
    await expect(twoFactor.getByRole('button', { name: 'Set up an authenticator app' })).toBeVisible();
});

test('a passkey is added, renamed, signs in and is removed', async ({ page, baseURL }, info) => {
    // WebAuthn needs a name, not 127.0.0.1; the device is Chrome's virtual one
    const origin = baseURL!.replace('127.0.0.1', 'localhost');
    const device = await page.context().newCDPSession(page);
    await device.send('WebAuthn.enable');
    await device.send('WebAuthn.addVirtualAuthenticator', {
        options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
    });

    await signIn(page, email(info), origin);
    await openProfile(page);
    const passkeys = section(page, 'Passkeys');
    await passkeys.getByLabel('Name for this device').fill('Test device');
    await passkeys.getByRole('button', { name: 'Add a passkey' }).click();
    await expect(passkeys.getByRole('list', { name: 'Your passkeys' })).toContainText('Test device');

    await passkeys.getByRole('button', { name: 'Rename Test device' }).click();
    await dialog(page).getByLabel('Name').fill('Renamed device');
    await dialog(page).getByRole('button', { name: 'Save name' }).click();
    await expect(passkeys.getByRole('list', { name: 'Your passkeys' })).toContainText('Renamed device');
    await expect(page.locator('[data-slot="dialog-viewport"]')).toBeHidden();
    await expectAccessible(page);
    await expectNoHorizontalOverflow(page);

    await signOut(page);
    freshBudgets();
    // The autofill offer may answer by itself on the virtual device; else the button does
    await page
        .getByRole('button', { name: 'Sign in with a passkey' })
        .click({ timeout: 5_000 })
        .catch(() => {});
    await arrived(page);
    await expect(heading(page)).toHaveText('Queue');

    await openProfile(page);
    await expect(section(page, 'Recent security activity')).toContainText('Signed in with a passkey');
    await passkeys.getByRole('button', { name: 'Remove Renamed device' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Remove' }).click();
    await expect(passkeys).toContainText('No passkeys yet.');
});

test('required two-factor lands here, with nothing else open until it is set up', async ({ page }, info) => {
    requireTwoFactor(info, true);

    try {
        const before = sentMail().length;
        await signIn(page, email(info));
        await expect(page).toHaveURL(/\/console\/operations\/profile$/);
        await expect(page.getByText('Set up two-factor sign-in to continue')).toBeVisible();
        await expect(section(page, 'Account')).toHaveCount(0);
        await expect(section(page, 'Passkeys')).toHaveCount(0);
        await page.goto('/console/operations/queue');
        await expect(page).toHaveURL(/\/console\/operations\/profile$/);
        await expectAccessible(page);
        await expectNoHorizontalOverflow(page);

        freshBudgets();
        await section(page, 'Two-factor sign-in').getByRole('button', { name: 'Set up emailed codes' }).click();
        await expect.poll(() => emailedCodeFor(email(info), before)).not.toBeNull();
        await dialog(page).getByRole('textbox', { name: 'Code' }).fill(emailedCodeFor(email(info), before)!);
        await dialog(page).getByRole('button', { name: 'Turn on' }).click();

        await page.getByRole('link', { name: 'Continue to the admin' }).click();
        await expect(heading(page)).toHaveText('Queue');
    } finally {
        requireTwoFactor(info, false);
    }
});
