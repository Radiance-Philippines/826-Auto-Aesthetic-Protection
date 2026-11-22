import { expect, test } from '@playwright/test';

import { adminLogin, adminOpen, inviteLinkFor, row, sentMail, TEAM } from './admin-support';
import { expectNoHorizontalOverflow } from './support';

test.describe('admin dashboard', () => {
    test('the dashboard renders its widgets without errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await adminLogin(page, TEAM.owner);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
        await expect(page.locator('.fi-wi-widget, .fi-widget').first()).toBeVisible();
        await expectNoHorizontalOverflow(page);
        expect(errors).toEqual([]);
    });
});

test.describe('admin email settings', () => {
    test('the page shows the mailbox fields, and a test send goes to the captured mail, not SMTP', async ({ page }, info) => {
        await adminLogin(page, TEAM.owner);
        await adminOpen(page, '/admin/email-settings');
        await expect(page.getByLabel('Email address')).toHaveValue('shop@826auto.test');
        await expect(page.getByLabel('Sender name')).toBeVisible();
        await expect(page.getByLabel('Mailbox password')).toBeVisible();
        await expect(page.getByLabel('Send booking alerts to')).toHaveValue('alerts@826auto.test');
        await expectNoHorizontalOverflow(page);

        await page.getByRole('button', { name: 'Save and send a test' }).click();
        await expect(page.getByText('Email works')).toBeVisible();
        expect(sentMail()).toContain('admin: test email');
        expect(sentMail()).toContain('shop@826auto.test');
        info.annotations.push({ type: 'mail', description: 'captured in storage/e2e/mail.log' });
    });
});

test.describe('admin invitations', () => {
    test('a super admin invites a member, the email is captured, and the link lets them choose a password and sign in', async ({ page, browser }, info) => {
        test.setTimeout(90_000);
        const email = `invited-${info.project.name}@826auto.test`;
        await adminLogin(page, TEAM.owner);
        await adminOpen(page, '/admin/users');
        await page.getByRole('button', { name: 'Invite team member' }).click();

        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Name').fill(`Invited ${info.project.name}`);
        await dialog.getByLabel('Email').fill(email);
        await dialog.getByLabel('Cainta – Ortigas Extension').check();
        await dialog.getByRole('button', { name: 'Send invite' }).click();
        await expect(page.getByText(`Invite sent to ${email}`)).toBeVisible();

        await expect(row(page, email)).toContainText('Invite sent');
        await expect.poll(() => inviteLinkFor(email)).not.toBeNull();
        expect(sentMail()).toContain("You're invited");

        // Accept the invite in a fresh browser: choose a password, then sign in with it
        const link = inviteLinkFor(email)!;
        const context = await browser.newContext({ baseURL: info.project.use.baseURL ?? undefined });
        const invited = await context.newPage();
        await invited.goto(link);
        await invited.locator('#form\\.password').fill('A-new-password-826!');
        await invited.locator('#form\\.passwordConfirmation').fill('A-new-password-826!');
        await invited.getByRole('button', { name: /Reset password|Save|Choose/i }).click();
        await invited.waitForLoadState('networkidle');

        await invited.goto('/admin/login');
        await invited.getByLabel('Email address').fill(email);
        await invited.locator('#form\\.password').fill('A-new-password-826!');
        await invited.getByRole('button', { name: 'Sign in', exact: true }).click();
        await invited.waitForURL((url) => !url.pathname.endsWith('/login'));
        await expect(invited.getByRole('link', { name: 'Queue' }).first()).toBeAttached();
        await context.close();
    });
});
