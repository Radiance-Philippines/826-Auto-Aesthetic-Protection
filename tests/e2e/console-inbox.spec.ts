import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { expect, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Inbox, on threads of this spec's own (the sample chats other
 * specs look at are only read).
 */
const BASE = '/console/sales/inbox';

/** Into the throwaway database tests/e2e/serve.sh made */
function tinker(code: string): void {
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '' },
        stdio: 'pipe',
    });
}

const removeChat = (name: string) => tinker(`\\App\\Models\\Conversation::query()->where('customer_name', '${name}')->get()->each(function ($chat) { $chat->messages()->delete(); $chat->delete(); });`);

/** A customer's Messenger chat; Meta is not connected here, so a reply is refused */
function messengerChat(name: string): void {
    removeChat(name);
    tinker(`
        $chat = \\App\\Models\\Conversation::create(['branch_id' => \\App\\Models\\Branch::query()->where('slug', 'cainta')->value('id'), 'external_id' => 'e2e-${name.replace(/\W/g, '')}', 'customer_name' => '${name}', 'unread_count' => 1, 'last_message_at' => now(), 'last_inbound_at' => now()->subDays(2), 'last_message_preview' => 'Magkano po ang coating?']);
        $chat->messages()->create(['direction' => 'in', 'body' => 'Magkano po ang coating?', 'sent_at' => now()->subDays(2)]);
    `);
}

const row = (page: Page, name: string) => page.getByRole('region', { name: 'Chats' }).getByRole('button').filter({ hasText: name });
const chat = (page: Page, name: string) => page.getByRole('region', { name: `Chat with ${name}` });

test('the chat list scrolls inside itself, without stretching the page', async ({ page }, info) => {
    test.setTimeout(90_000);
    const names = Array.from({ length: 8 }, (_, index) => `E2E Tall ${info.project.name} ${index}`);
    names.forEach(messengerChat);

    try {
        await adminLogin(page, TEAM.sales);
        await page.goto(BASE);
        await expect(row(page, names[0])).toBeAttached();
        // Blank space below the screen: the page is taller than what is on it
        const extra = await page.evaluate(() => document.documentElement.scrollHeight - Math.round(document.querySelector('#main')!.getBoundingClientRect().bottom + window.scrollY));
        expect(extra).toBeLessThanOrEqual(1);
    } finally {
        names.forEach(removeChat);
    }
});

test('a team thread is started, answered, assigned and finished', async ({ page }, info) => {
    test.setTimeout(90_000);
    const subject = `E2E Thread ${info.project.name}${info.retry}`;
    removeChat(subject);
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.sales);
    await page.goto(BASE);
    await expect(page.getByRole('heading', { level: 1, name: 'Inbox' })).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'New team thread' }).click();
    const starting = page.getByRole('dialog', { name: 'New team thread' });
    await expect(starting.getByRole('button', { name: 'Start thread' })).toBeInViewport({ ratio: 1 });
    await starting.getByRole('button', { name: 'Start thread' }).click();
    await expect(starting.getByRole('textbox', { name: 'Subject' })).toHaveAttribute('aria-invalid', 'true');
    await starting.getByRole('textbox', { name: 'Subject' }).fill(subject);
    await starting.getByRole('textbox', { name: 'Message' }).fill('Microfiber stock is low');
    await starting.getByRole('button', { name: 'Start thread' }).click();
    await expect(starting).toBeHidden();

    const thread = chat(page, subject);
    await expect(thread.getByRole('log')).toContainText('Microfiber stock is low');
    await expect(thread).toContainText('it is never sent to a customer or to Messenger');
    await expect(thread.getByRole('button', { name: 'Link customer' })).toHaveCount(0);

    await thread.getByRole('textbox', { name: 'Reply' }).fill('Ordered today');
    await thread.getByRole('textbox', { name: 'Reply' }).press('Control+Enter');
    await expect(thread.getByRole('log')).toContainText('Ordered today');
    await expect(thread.getByRole('textbox', { name: 'Reply' })).toHaveValue('');

    await thread.getByRole('button', { name: 'Assign' }).click();
    const assigning = page.getByRole('dialog', { name: 'Assign this chat' });
    await expect(assigning.getByRole('button', { name: 'Save' })).toBeInViewport({ ratio: 1 });
    await assigning.getByRole('combobox', { name: 'Team member' }).click();
    await page.getByRole('option', { name: 'Sales Admin' }).click();
    await assigning.getByRole('button', { name: 'Save' }).click();
    await expect(thread.getByRole('button', { name: 'Assigned: Sales Admin' })).toBeVisible();

    await thread.getByRole('button', { name: 'Mark done' }).click();
    await expect(thread.getByRole('button', { name: 'Reopen' })).toBeVisible();

    // Allowed: the 422 asked for above, and a look-up dropped because the screen moved on
    expect(problems().filter((problem) => !problem.includes('422') && !problem.includes('ERR_ABORTED'))).toEqual([]);
});

test('a Messenger chat is read, linked to a customer, and a refused reply says why', async ({ page }, info) => {
    test.setTimeout(90_000);
    const name = `E2E Chat ${info.project.name}${info.retry}`;
    messengerChat(name);
    await adminLogin(page, TEAM.sales);
    await page.goto(BASE);
    await page.getByRole('searchbox', { name: 'Search chats' }).fill(name);
    await expect(row(page, name)).toHaveCount(1);
    await expect(row(page, name)).toContainText('1 unread');
    await row(page, name).click();
    await expect(page).toHaveURL(/conversation=\d+/);

    const thread = chat(page, name);
    await expect(thread.getByRole('log')).toContainText('Magkano po ang coating?');
    await expect(thread).toContainText('It has been over 24 hours');
    await expectNoHorizontalOverflow(page);

    await thread.getByRole('button', { name: 'Link customer' }).click();
    const linking = page.getByRole('dialog', { name: 'Link this chat to a customer' });
    await expect(linking.getByRole('button', { name: 'Save' })).toBeInViewport({ ratio: 1 });
    await linking.getByRole('combobox', { name: 'Customer' }).fill('E2E Cust');
    await page.getByRole('option', { name: /^E2E Customer/ }).click();
    await linking.getByRole('button', { name: 'Save' }).click();
    await expect(thread.getByRole('link', { name: 'Customer: E2E Customer' })).toBeVisible();
    await expect(thread.getByRole('button', { name: 'Change customer' })).toBeVisible();

    await thread.getByRole('textbox', { name: 'Reply' }).fill('Hello po');
    await thread.getByRole('button', { name: 'Send' }).click();
    await expect(thread.getByRole('alert')).toContainText('Message not sent');
    await expect(thread.getByRole('alert')).toContainText('Messenger isn');

    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-inbox-${info.project.name}.png` });
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-inbox-${info.project.name}-dark.png` });
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await thread.getByRole('button', { name: 'Back to chats' }).click();
    await expect(page.getByRole('region', { name: 'Chats' })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    removeChat(name);
});

test('a detailer has no inbox, and a chat that is not there says so', async ({ page }) => {
    await adminLogin(page, TEAM.operations);
    const response = await page.goto('/console/operations/inbox');
    expect(response?.status()).toBe(403);

    await page.goto('about:blank');
    await page.context().clearCookies();
    await adminLogin(page, TEAM.sales);
    await page.goto(`${BASE}?conversation=999999`);
    await expect(page.getByRole('region', { name: 'Chat', exact: true })).toContainText('That isn’t there any more.');
});
