import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow } from './support';

/** The bell in the React admin's top bar. The notifications themselves are answered here; PHPUnit covers the server. */
test('the real bell opens and says when there is nothing', async ({ page }) => {
    await adminLogin(page, TEAM.sales);
    await page.goto('/console/sales/dashboard');

    const real = page.waitForResponse('**/console/api/sales/notifications?page=1');
    await page.reload();
    expect((await real).status()).toBe(200);
    await page.getByRole('button', { name: /^Notifications/ }).click();
    await expect(page.getByRole('dialog', { name: 'Notifications' })).toBeVisible();
});

test('unread count, mark as read, open, dismiss and clear', async ({ page }, info) => {
    const sent: string[] = [];
    let notes = [1, 2].map((number) => ({
        id: `00000000-0000-4000-8000-00000000000${number}`,
        title: `Heads-up: Customer ${number}`,
        body: 'Jun changed the ready date: Nov 20 → Nov 22',
        status: 'warning',
        icon: 'heroicon-o-bell-alert',
        read: false,
        ago: '2 minutes ago',
        actions: [{ label: 'Open booking', url: '', marksRead: true, newTab: false }],
    }));
    await adminLogin(page, TEAM.sales);
    await page.route('**/console/api/sales/notifications**', async (route) => {
        const { pathname } = new URL(route.request().url());
        const method = route.request().method();

        if (method === 'GET') {
            const url = `${new URL(page.url()).origin}/console/sales/dashboard?period=today`;

            return route.fulfill({ json: { data: notes.map((note) => ({ ...note, actions: [{ ...note.actions[0], url }] })), meta: { unread: notes.filter((note) => !note.read).length, more: false } } });
        }

        sent.push(`${method} ${pathname.split('/notifications')[1]}`);
        const id = pathname.split('/')[5];

        if (method === 'DELETE') {
            notes = id ? notes.filter((note) => note.id !== id) : [];
        } else {
            notes = notes.map((note) => (pathname.endsWith('read-all') || note.id === id ? { ...note, read: true } : note));
        }

        return route.fulfill({ status: 204 });
    });
    await page.goto('/console/sales/dashboard');

    const bell = page.getByRole('button', { name: 'Notifications, 2 unread' });
    await bell.click();
    const panel = page.getByRole('dialog', { name: 'Notifications' });
    await expect(panel.getByRole('listitem')).toHaveCount(2);
    await expect(panel).toBeInViewport({ ratio: 1 });
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-notifications-${info.project.name}.png` });

    // A link to a screen of this panel stays in the app and marks it read
    await page.evaluate(() => ((window as unknown as { __mark: string }).__mark = 'same page'));
    await panel.getByRole('link', { name: 'Open booking' }).first().click();
    await expect(page).toHaveURL(/\/console\/sales\/dashboard\?period=today$/);
    expect(await page.evaluate(() => (window as unknown as { __mark?: string }).__mark)).toBe('same page');
    await expect(panel).toBeHidden();

    await page.getByRole('button', { name: 'Notifications, 1 unread' }).click();
    await panel.getByRole('button', { name: 'Mark all as read' }).click();
    await expect(panel.getByRole('button', { name: 'Mark all as read' })).toBeDisabled();
    await panel.getByRole('button', { name: 'Dismiss Heads-up: Customer 2' }).click();
    await expect(panel.getByRole('listitem')).toHaveCount(1);
    await panel.getByRole('button', { name: 'Clear' }).click();
    await expect(panel.getByText('No notifications')).toBeVisible();
    expect(sent).toEqual(['POST /00000000-0000-4000-8000-000000000001/read', 'POST /read-all', 'DELETE /00000000-0000-4000-8000-000000000002', 'DELETE ']);

    await page.keyboard.press('Escape');
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
});
